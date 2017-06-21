<?php
namespace GraphQL\Language;

use GraphQL\Utils;

class Source
{
    /**
     * @var string
     */
    public $body;

    /**
     * @var array
     */
    public $buf;

    /**
     * @var int
     */
    public $length;

    /**
     * @var string
     */
    public $name;

    public function __construct($body, $name = null)
    {
        Utils::invariant(
            is_string($body),
            'GraphQL query body is expected to be string, but got ' . Utils::getVariableType($body)
        );

        $this->body = $body;

        $buf = [];

        $i = 0;

        // Check for a UTF-8 BOM
        if (strlen($body) > 2
                   && ord($body[0]) == 0xEF
                   && ord($body[1]) == 0xBB
                   && ord($body[2]) == 0xBF) {
          $buf[] = 0xFEFF;
          $i = 3;
        }

        for(; $i < strlen($body);) {
          $byte = ord($body[$i]);
          $ord = 0;

          if ($byte < 0x80) {
            $ord = ord($body[$i++]);
          }

          elseif (($byte & 0xE0) === 0xC0 ) {
            $ord =  (ord($body[$i++]) & 0x1F) << 6;
            $ord |= (ord($body[$i++]) & 0x3F);
          }

          elseif (($byte & 0xF0) === 0xE0 ) {
            $ord =  (ord($body[$i++]) & 0x0F) << 12;
            $ord |= (ord($body[$i++]) & 0x3F) << 6;
            $ord |= (ord($body[$i++]) & 0x3F);
          }

          elseif (($byte & 0xF8) === 0xF0 ) {
            $ord =  (ord($body[$i++]) & 0x07) << 18;
            $ord |= (ord($body[$i++]) & 0x3F) << 12;
            $ord |= (ord($body[$i++]) & 0x3F) << 6;
            $ord |= (ord($body[$i++]) & 0x3F);
          }

          $buf[] = $ord;
        }

        $this->buf =  $buf;
        $this->length = count($this->buf);
        $this->name = $name ?: 'GraphQL';
    }

    /**
     * @param $position
     * @return SourceLocation
     */
    public function getLocation($position)
    {
        $line = 1;
        $column = $position + 1;

        $utfChars = json_decode('"\u2028\u2029"');
        $lineRegexp = '/\r\n|[\n\r'.$utfChars.']/su';
        $matches = [];
        preg_match_all($lineRegexp, mb_substr($this->body, 0, $position, 'UTF-8'), $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $line += 1;
            $column = $position + 1 - ($match[1] + mb_strlen($match[0], 'UTF-8'));
        }

        return new SourceLocation($line, $column);
    }
}
