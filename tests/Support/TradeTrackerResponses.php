<?php

namespace Tests\Support;

/** Synthetic SOAP fixtures using pinned WSDL types, not live evidence. */
class TradeTrackerResponses
{
    public static function response(string $method, ?array $rows = null): string
    {
        $result = $rows === null ? '' : '<result xsi:type="enc:Array" enc:arrayType="xsd:anyType['.count($rows).']">'.implode('', array_map(fn ($row) => self::value('item', $row), $rows)).'</result>';

        return '<?xml version="1.0"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/" xmlns:enc="http://schemas.xmlsoap.org/soap/encoding/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:ns="https://ws.tradetracker.com/soap/affiliate"><env:Body><ns:'.$method.'Response>'.$result.'</ns:'.$method.'Response></env:Body></env:Envelope>';
    }

    public static function fault(string $message): string
    {
        return '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault><faultcode>Server</faultcode><faultstring>'.htmlspecialchars($message, ENT_XML1).'</faultstring></env:Fault></env:Body></env:Envelope>';
    }

    private static function value(string $name, mixed $value): string
    {
        if (is_array($value)) {
            $xml = '';
            foreach ($value as $key => $child) {
                $xml .= self::value((string) $key, $child);
            }

            return '<'.$name.'>'.$xml.'</'.$name.'>';
        }
        if ($value === null) {
            return '<'.$name.' xsi:nil="true"/>';
        }
        $type = is_bool($value) ? 'boolean' : (in_array($name, ['commission', 'orderAmount', 'saleCommissionVariable'], true) ? 'decimal' : 'string');
        $text = is_bool($value) ? ($value ? 'true' : 'false') : htmlspecialchars((string) $value, ENT_XML1);

        return '<'.$name.' xsi:type="xsd:'.$type.'">'.$text.'</'.$name.'>';
    }
}
