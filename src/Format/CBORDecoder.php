<?php


namespace MadWizard\WebAuthn\Format;

use MadWizard\WebAuthn\Exception\ByteBufferException;
use MadWizard\WebAuthn\Exception\CBORException;

class CBORDecoder
{
    /**
     * @param ByteBuffer $buf
     * @return mixed
     * @throws CBORException
     */
    public static function decode(ByteBuffer $buf)
    {
        try {
            // TODO: wrap exceptions from bytebuffer
            $offset = 0;
            $result = self::parseItem($buf, $offset);
            if ($offset !== $buf->getLength()) {
                throw new CBORException('Unused bytes after data item.');
            }
            return $result;
        } catch (ByteBufferException $e) {
            throw new CBORException(sprintf('Error with byte buffer during parsing: %s.', $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param ByteBuffer $buf
     * @param int $startOffset
     * @param int|null $endOffset
     * @return mixed
     * @throws CBORException
     */
    public static function decodeInPlace(ByteBuffer $buf, int $startOffset, int &$endOffset = null)
    {
        try {
            $offset = $startOffset;
            $data = self::parseItem($buf, $offset);
            $endOffset = $offset;
            return $data;
        } catch (ByteBufferException $e) {
            throw new CBORException(sprintf('Error with byte buffer during parsing: %s.', $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param ByteBuffer $buf
     * @param int $offset
     * @return mixed
     */
    private static function parseItem(ByteBuffer $buf, int &$offset)
    {
        $first = $buf->getByteVal($offset++);
        $type = $first >> 5;
        $val = $first & 0b11111;

        if ($type === CBOR::MAJOR_FLOAT_SIMPLE) {
            return self::parseFloatSimple($val, $buf, $offset);
        }

        $val = self::parseExtraLength($val, $buf, $offset);

        return self::parseItemData($type, $val, $buf, $offset);
    }

    private static function parseFloatSimple(int $val, ByteBuffer $buf, int &$offset)
    {
        switch ($val) {
            case 24:
                $val = $buf->getByteVal($offset);
                $offset++;
                return self::parseSimple($val);
            case 25:
                $floatValue = $buf->getHalfFloatVal($offset);
                $offset += 2;
                return $floatValue;
            case 26:
                $floatValue = $buf->getFloatVal($offset);
                $offset += 4;
                return $floatValue;
            case 27:
                $floatValue = $buf->getDoubleVal($offset);
                $offset += 8;
                return $floatValue;
            case 28:
            case 29:
            case 30:
                throw new CBORException('Reserved value used.');
            case 31:
                throw new CBORException('Indefinite length is not supported.');
        }

        return self::parseSimple($val);
    }

    /**
     * @param $val
     * @return mixed
     * @throws CBORException
     */
    private static function parseSimple($val)
    {
        if ($val === 20) {
            return false;
        }
        if ($val === 21) {
            return true;
        }
        if ($val === 22) {
            return null;
        }
        throw new CBORException(sprintf('Unsupported simple value %d.', $val));
    }

    private static function parseExtraLength(int $val, ByteBuffer $buf, int &$offset) : int
    {
        switch ($val) {
            case 24:
                $val = $buf->getByteVal($offset);
                $offset++;
                break;
            case 25:
                $val = $buf->getUint16Val($offset);
                $offset += 2;
                break;
            case 26:
                $val = $buf->getUint32Val($offset);
                $offset += 4;
                break;
            case 27:
                $val = $buf->getUint64Val($offset);
                $offset += 8;
                break;
            case 28:
            case 29:
            case 30:
                throw new CBORException('Reserved value used.');
            case 31:
                throw new CBORException('Indefinite length is not supported.');
        }

        return $val;
    }

    private static function parseItemData(int $type, int $val, ByteBuffer $buf, int &$offset)
    {
        switch ($type) {
            case CBOR::MAJOR_UNSIGNED_INT: // uint
                return $val;
            case CBOR::MAJOR_NEGATIVE_INT:
                return -1 - $val;
            case CBOR::MAJOR_BYTE_STRING:
                $data = $buf->getBytes($offset, $val);
                $offset += $val;
                return new ByteBuffer($data); // bytes
            case CBOR::MAJOR_TEXT_STRING:
                $data = $buf->getBytes($offset, $val);
                $offset += $val;
                return $data; // UTF-8
            case CBOR::MAJOR_ARRAY:
                return self::parseArray($buf, $offset, $val);
            case CBOR::MAJOR_MAP:
                return self::parseMap($buf, $offset, $val);
            case CBOR::MAJOR_TAG:
                return self::parseItem($buf, $offset); // 1 embedded data item
        }

        // This should never be reached
        throw new CBORException(sprintf('Unknown major type %d.', $type));
    }

    private static function parseMap(ByteBuffer $buf, int &$offset, int $count) : array
    {
        $map = [];

        for ($i = 0; $i < $count; $i++) {
            $mapKey = self::parseItem($buf, $offset);
            $mapVal = self::parseItem($buf, $offset);
            if (!\is_int($mapKey) && !\is_string($mapKey)) {
                throw new CBORException('Can only use strings or integers as map keys');
            }
            $map[$mapKey] = $mapVal; // todo dup
        }
        return $map;
    }

    private static function parseArray(ByteBuffer $buf, int &$offset, int $count) : array
    {
        $arr = [];
        for ($i = 0; $i < $count; $i++) {
            $arr[] = self::parseItem($buf, $offset);
        }
        return $arr;
    }
}
