<?php
declare(strict_types=1);

namespace XPOHOC269\graylog;

/**
 * Class GraylogMessage
 * хелпер для формирования массива для грейлога
 *
 * @package app\components
 */
class GraylogMessage
{
    /**
     * @param string $message сообщение для грейлога
     * @param array $additionalFields дополнительные поля для грейлога в стиле [название поля => значение]
     * @return array массив, в файловом логгере запишется как обычный массив, в виде строки. В грейлог логгере GraylogTarget
     * массив распарсится и из него извлечётся основное сообщение и дополнительные поля. По дополнительным полям в
     * грейлоге можно удобно фильтроваться/сортироваться.
     *
     * Пример использования Yii::info(GraylogMessage::make('test', ['param' => 123321]), __METHOD__);
     */
    public static function make(string $message, array $additionalFields = []): array
    {
        return [
            'message' => $message,
            'add'     => $additionalFields,
        ];
    }
}