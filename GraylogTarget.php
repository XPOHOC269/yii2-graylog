<?php
declare(strict_types=1);

namespace xpohoc269\graylog;

use Gelf;
use Psr\Log\LogLevel;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;
use yii\web\Request;

/**
 * Class GraylogTarget
 * @package xpohoc269\graylog
 */
class GraylogTarget extends Target
{
    private $logStep = 0;
    private $req = '';
    public $source = '';

    /**
     * @var string Graylog2 host
     */
    public $host = '127.0.0.1';

    /**
     * @var integer Graylog2 port
     */
    public $port = 12201;

    /**
     * @var string default facility name
     */
    public $facility = 'yii2-logs';

    /**
     * @var array default additional fields
     */
    public $additionalFields = [];

    /**
     * @var boolean whether to add authenticated user username to additional fields
     */
    public $addUsername = false;

    /**
     * @var int chunk size
     */
    public $chunkSize = Gelf\Transport\UdpTransport::CHUNK_SIZE_LAN;

    /**
     * @var array graylog levels
     */
    private $_levels = [
        Logger::LEVEL_TRACE         => LogLevel::DEBUG,
        Logger::LEVEL_PROFILE_BEGIN => LogLevel::DEBUG,
        Logger::LEVEL_PROFILE_END   => LogLevel::DEBUG,
        Logger::LEVEL_INFO          => LogLevel::INFO,
        Logger::LEVEL_WARNING       => LogLevel::WARNING,
        Logger::LEVEL_ERROR         => LogLevel::ERROR,
    ];

    public function __construct($config = [])
    {
        parent::__construct($config);
        if (!$this->source) {
            throw new InvalidConfigException('graylog source not set');
        }
    }

    /**
     * Sends log messages to Graylog2 input
     */
    public function export()
    {
        $transport = new Gelf\Transport\UdpTransport($this->host, $this->port, $this->chunkSize);
        $publisher = new Gelf\Publisher($transport);
        foreach ($this->messages as $message) {
            $gelfMsg = new Gelf\Message();
            [$text, $level, $category, $timestamp] = $message;
            unset($message);
            if (!is_string($text)) {
                // exceptions may not be serializable if in the call stack somewhere is a Closure
                if ($text instanceof \Exception) {
                    $text = 'Exception: ' . ((string)$text);
                } elseif (is_array($text) && isset($text['add'], $text['message']) && is_array($text['add'])) {
                    foreach ($text['add'] as $k => $v) {
                        $gelfMsg->setAdditional($k, $v);
                    }
                    $text = $text['message'];
                } else {
                    $text = VarDumper::export($text);
                }
            }

            $ip = $this->getIp();
            $userId = $this->getUserID();
            $sessionId = $this->getSessionID();
            $levelName = Logger::getLevelName($level);
            $requestId = $this->getRequestId();
            $requestUri = $this->getRequestUri();
            $traceId = $this->getTraceId();
            $transactionId = $this->getTransactionId();

            $gelfMsg->setVersion('1.1');
            $gelfMsg->setLevel(ArrayHelper::getValue($this->_levels, $level, LogLevel::INFO));
            $gelfMsg->setHost($this->source);

            ++$this->logStep;
            $logStep = $this->logStep;
            if (strpos($text, 'end proceed transaction') !== false) {
                $this->logStep = 0;
                $this->req = '';
            }

            $gelfMsg->setTimestamp($timestamp);
            $gelfMsg->setAdditional('logStep', $logStep);
            $gelfMsg->setAdditional('ip', $ip);
            $gelfMsg->setAdditional('userID', $userId);
            $gelfMsg->setAdditional('sessionID', $sessionId);
            $gelfMsg->setAdditional('levelName', $levelName);
            $gelfMsg->setAdditional('category', $category);
            $gelfMsg->setAdditional('requestID', $requestId);
            $gelfMsg->setAdditional('requestURI', $requestUri);
            $gelfMsg->setAdditional('transactionID', $transactionId);
            $gelfMsg->setAdditional('microtime', str_replace('.', '', $timestamp));
            $gelfMsg->setAdditional('traceId', $traceId);

            $parts = explode('.', sprintf('%F', $timestamp));
            $dateTime = date('Y-m-d H:i:s', $parts[0]) . '.' . $parts[1];

            $addText = "$dateTime [$ip][$userId][$sessionId][$levelName][$category][$requestId][$requestUri] [$transactionId]";
            $gelfMsg->setShortMessage("{$addText} {$text}");
            $gelfMsg->setFullMessage("{$addText} {$text}");


            // Publish message
            $publisher->publish($gelfMsg);
        }
    }

    private function getIp()
    {
        if (Yii::$app === null) {
            return '';
        }

        $request = Yii::$app->getRequest();

        return $request instanceof Request ? $request->getUserIP() : '-';
    }

    private function getUserID()
    {
        if (Yii::$app === null) {
            return '';
        }
        /* @var $user \yii\web\User */
        $user = Yii::$app->has('user', true) ? Yii::$app->get('user') : null;
        if ($user && ($identity = $user->getIdentity(false))) {
            $userID = $identity->getId();
        } else {
            $userID = '-';
        }

        return $userID;
    }

    private function getSessionID()
    {
        if (Yii::$app === null) {
            return '';
        }

        /* @var $session \yii\web\Session */
        $session = Yii::$app->has('session', true) ? Yii::$app->get('session') : null;

        return $session && $session->getIsActive() ? $session->getId() : '-';
    }

    protected function getRequestId()
    {
        if (\Yii::$app === null) {
            return '';
        }

        try {
            if (!$this->req) {
                if (isset($_SERVER['REQUEST_ID'])) {
                    $this->req = $_SERVER['REQUEST_ID'];
                } else {
                    $this->req = uniqid('request_id_', true);
                }
            }
        } catch (\Exception $e) {
        }

        return $this->req;
    }

    protected function getRequestUri()
    {
        if (\Yii::$app === null) {
            return '';
        }
        $uri = '-';

        try {
            /** @var \yii\console\Request $request */
            $request = \Yii::$app->getRequest();
            if ($request instanceof \yii\web\Request) {
                $uri = \Yii::$app->request->getUrl();
            }
            if ($request instanceof \yii\console\Request) {
                $uri = \Yii::$app->request->getParams();
                $uri = '/' . implode('?', $uri);
            }
        } catch (\Exception $e) {
        }

        return $uri;
    }

    protected function getTransactionId()
    {
        $transactionId = '-';
        try {
            $transactionId = \Yii::$app->Paybox->transId ?: '-';
        } catch (\Exception $e) {
        }

        return $transactionId;
    }

    private function getTraceId()
    {
        if (\Yii::$app === null) {
            return '-';
        }
        $traceId = '-';

        try {
            /** @var \yii\base\Request $request */
            $request = \Yii::$app->getRequest();
            if ($request instanceof \yii\console\Request) {
                return '-';
            }
            if ($request instanceof \yii\web\Request) {
                $traceId = \Yii::$app->request->get('traceId', '-');
            }

        } catch (\Exception $e) {
        }

        return $traceId;
    }
}