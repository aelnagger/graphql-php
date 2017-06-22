<?php
namespace GraphQL\Language;

use GraphQL\Error\SyntaxError;
use GraphQL\Utils;

/**
 * A Lexer is a stateful stream generator in that every time
 * it is advanced, it returns the next token in the Source. Assuming the
 * source lexes, the final Token emitted by the lexer will be of kind
 * EOF, after which the lexer will repeatedly return the same EOF token
 * whenever called.
 */
class Lexer
{
    /**
     * @var Source
     */
    public $source;

    /**
     * @var array
     */
    public $options;

    /**
     * The previously focused non-ignored token.
     *
     * @var Token
     */
    public $lastToken;

    /**
     * The currently focused non-ignored token.
     *
     * @var Token
     */
    public $token;

    /**
     * The (1-indexed) line containing the current token.
     *
     * @var int
     */
    public $line;

    /**
     * The character offset at which the current line begins.
     *
     * @var int
     */
    public $lineStart;

    /**
     * The current parse position of the lexer.
     *
     * @var int
     */
    private $position;

  /**
   * How many characters into the source text we are.
   *
   * @var int
   */
    private $characterPosition = -1;

  /**
   * The last character that was read by the lexer.
   *
   * @var int
   */
    private $currentCharacter;

    private $isEOF = false;

    /**
     * Lexer constructor.
     * @param Source $source
     * @param array $options
     */
    public function __construct(Source $source, array $options = [])
    {
        $startOfFileToken = new Token(Token::SOF, 0, 0, 0, 0, null);

        $this->source = $source;
        $this->options = $options;
        $this->lastToken = $startOfFileToken;
        $this->token = $startOfFileToken;
        $this->line = 1;
        $this->lineStart = 0;
        $this->position = 0;

        // Prime the lexer.
        $this->readCharacter();
    }

    /**
     * Advance the lexer and return the next token in the stream.
     *
     * @return Token
     */
    public function advance()
    {
        $token = $this->lastToken = $this->token;

        if ($token->kind !== Token::EOF) {
            do {
                $token = $token->next = $this->readToken($token);
            } while ($token->kind === Token::COMMENT);
            $this->token = $token;
        }
        return $token;
    }

    /**
     * @return Token
     */
    public function nextToken()
    {
        trigger_error(__METHOD__ . ' is deprecated in favor of advance()', E_USER_DEPRECATED);
        return $this->advance();
    }

    /**
     * Read a token from the internal buffer.
     *
     * @param Token $prev
     * @return Token
     * @throws SyntaxError
     */
    private function readToken(Token $prev)
    {
        $this->readWhitespace();
        $line = $this->line;
        $col = 1 + $this->characterPosition - $this->lineStart;

        if ($this->isEOF) {
            return new Token(Token::EOF, $this->characterPosition, $this->characterPosition, $line, $col, $prev);
        }

        $code = $this->currentCharacter;
        $startPosition = $this->characterPosition;
        // SourceCharacter
        if ($code < 0x0020 && $code !== 0x0009 && $code !== 0x000A && $code !== 0x000D) {
            throw new SyntaxError(
                $this->source,
                $this->characterPosition,
                'Cannot contain the invalid character ' . Utils::printCharCode($code)
            );
        }

        switch ($code) {
            case 33: // !
                $token = new Token(Token::BANG, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 35: // #
                return $this->readComment($startPosition, $line, $col, $prev);
            case 36: // $
                $token = new Token(Token::DOLLAR, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 40: // (
                $token = new Token(Token::PAREN_L, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 41: // )
                $token = new Token(Token::PAREN_R, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 46: // .
                if ($this->source->length - $this->position > 1 &&
                    $this->peekByte() === 46 &&
                    $this->peekByte(1) === 46) {
                    $this->readCharacter();
                    $this->readCharacter();
                    $token = new Token(Token::SPREAD, $startPosition, $this->position, $line, $col, $prev);
                    $this->readCharacter();
                    return $token;
                }
                break;
            case 58: // :
                $token = new Token(Token::COLON, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 61: // =
                $token = new Token(Token::EQUALS, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 64: // @
                $token = new Token(Token::AT, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 91: // [
                $token = new Token(Token::BRACKET_L, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 93: // ]
                $token = new Token(Token::BRACKET_R, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 123: // {
                $token = new Token(Token::BRACE_L, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 124: // |
                $token = new Token(Token::PIPE, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            case 125: // }
                $token = new Token(Token::BRACE_R, $startPosition, $this->position, $line, $col, $prev);
                $this->readCharacter();
                return $token;
            // A-Z
            case 65: case 66: case 67: case 68: case 69: case 70: case 71: case 72:
            case 73: case 74: case 75: case 76: case 77: case 78: case 79: case 80:
            case 81: case 82: case 83: case 84: case 85: case 86: case 87: case 88:
            case 89: case 90:
            // _
            case 95:
            // a-z
            case 97: case 98: case 99: case 100: case 101: case 102: case 103: case 104:
            case 105: case 106: case 107: case 108: case 109: case 110: case 111:
            case 112: case 113: case 114: case 115: case 116: case 117: case 118:
            case 119: case 120: case 121: case 122:
                return $this->readName($startPosition, $line, $col, $prev);
            // -
            case 45:
            // 0-9
            case 48: case 49: case 50: case 51: case 52:
            case 53: case 54: case 55: case 56: case 57:
                return $this->readNumber($startPosition, $code, $line, $col, $prev);
            // "
            case 34:
                return $this->readString($startPosition, $line, $col, $prev);
        }

        $errMessage = $code === 39
                    ? "Unexpected single quote character ('), did you mean to use ". 'a double quote (")?'
                    : 'Cannot parse the unexpected character ' . Utils::printCharCode($code) . '.';

        throw new SyntaxError(
            $this->source,
            $startPosition,
            $errMessage
        );
    }

    /**
     * Reads an alphanumeric + underscore name from the source.
     *
     * [_A-Za-z][_0-9A-Za-z]*
     *
     * @param int $position
     * @param int $line
     * @param int $col
     * @param Token $prev
     * @return Token
     */
    private function readName($position, $line, $col, Token $prev)
    {
        $value = Utils::chr($this->currentCharacter);

        while (
            ($code = $this->readCharacter()) &&
            (
                $code === 95 || // _
                $code >= 48 && $code <= 57 || // 0-9
                $code >= 65 && $code <= 90 || // A-Z
                $code >= 97 && $code <= 122 // a-z
            )
        ) {
            $value .= Utils::chr($code);
        }
        return new Token(
            Token::NAME,
            $position,
            $this->characterPosition,
            $line,
            $col,
            $prev,
            $value
        );
    }

    /**
     * Reads a number token from the source file, either a float
     * or an int depending on whether a decimal point appears.
     *
     * Int:   -?(0|[1-9][0-9]*)
     * Float: -?(0|[1-9][0-9]*)(\.[0-9]+)?((E|e)(+|-)?[0-9]+)?
     *
     * @param int $start
     * @param string $firstCode
     * @param int $line
     * @param int $col
     * @param Token $prev
     * @return Token
     * @throws SyntaxError
     */
    private function readNumber($start, $firstCode, $line, $col, Token $prev)
    {
        $isFloat = false;
        $code = $firstCode;
        $value = chr($firstCode);

        if ($code === 45) { // -
            $code = $this->readCharacter();
            $value .= chr($code);
        }

        // guard against leading zero's
        if ($code === 48) { // 0
            $code = $this->readCharacter();

            if ($code >= 48 && $code <= 57) {
                throw new SyntaxError($this->source, $this->position - 1, "Invalid number, unexpected digit after 0: " . Utils::printCharCode($code));
            }
        } else {
            $value .= $this->readDigits($this->position);
            $code = $this->currentCharacter;
        }

        if ($code === 46) { // .
            $isFloat = true;
            $value .= chr($code);
            $value .= chr($this->readCharacter());
            $value .= $this->readDigits($this->position);
            $code = $this->currentCharacter;
        }

        if ($code === 69 || $code === 101) { // E e
            $isFloat = true;
            $value .= chr($code);
            $code = $this->readCharacter();
            $value .= chr($code);

            if ($code === 43 || $code === 45) { // + -
                $value .= chr($this->readCharacter());
            }

            $value .= $this->readDigits($this->position);
        }

        return new Token(
            $isFloat ? Token::FLOAT : Token::INT,
            $start,
            $this->characterPosition,
            $line,
            $col,
            $prev,
            $value
        );
    }

    /**
     * Return a series of digits.
     *
     * @param $start
     * @return string
     * @throws SyntaxError
     */
    private function readDigits($start)
    {
        $code = $this->currentCharacter;

        $value = '';

        // Throw if out of range.
        if ($code < 48 || $code > 57) {
            if ($this->isEOF) {
                $code = null;
            }

            throw new SyntaxError(
                $this->source,
                $this->characterPosition,
                'Invalid number, expected digit but got: ' . Utils::printCharCode($code)
            );
        }

        while (
          ($code = $this->readCharacter()) &&
          $code >= 48 && $code <= 57 // 0 - 9
        ) {
          $value .= chr($code);
        }

        return $value;
    }

    /**
     * Read a string token from the buffer.
     *
     * @param int $start
     * @param int $line
     * @param int $col
     * @param Token $prev
     * @return Token
     * @throws SyntaxError
     */
    private function readString($start, $line, $col, Token $prev)
    {
        $value = '';
        $offset = 0;

        while (
            ($code = $this->readCharacter()) &&
            // not Quote (")
            $code !== 34 &&
            !$this->isEOF

        ) {
          if ($code === 0x0A || $code === 0x0D) {
            break;
          }

            $this->assertValidStringCharacterCode($code, $this->position);

            if ($code === 92) { // \
                $offset--;
                $code = $this->readCharacter();
                switch ($code) {
                    case 34: $value .= '"'; break;
                    case 47: $value .= '/'; break;
                    case 92: $value .= '\\'; break;
                    case 98: $value .= chr(8); break; // \b (backspace)
                    case 102: $value .= "\f"; break;
                    case 110: $value .= "\n"; break;
                    case 114: $value .= "\r"; break;
                    case 116: $value .= "\t"; break;
                    case 117:
                        $hex  = chr($this->readCharacter());
                        $hex .= chr($this->readCharacter());
                        $hex .= chr($this->readCharacter());
                        $hex .= chr($this->readCharacter());
                        if (!preg_match('/[0-9a-fA-F]{4}/', $hex)) {
                            throw new SyntaxError(
                                $this->source,
                                $this->position - 4 + $offset,
                                'Invalid character escape sequence: \\u' . $hex
                            );
                        }
                        $code = hexdec($hex);
                        $this->assertValidStringCharacterCode($code, $this->position - 5 + $offset);
                        $value .= Utils::chr($code);
                        break;
                    default:
                        throw new SyntaxError(
                            $this->source,
                            $this->position + $offset,
                            'Invalid character escape sequence: \\' . Utils::chr($code)
                        );
                }
            } else {
                $value .= Utils::chr($code);
            }
        }

        if ($code !== 34) {
            throw new SyntaxError(
                $this->source,
                $this->characterPosition + $offset,
                'Unterminated string.'
            );
        }

        // Advance past the ending quote.
        $this->readCharacter();

        return new Token(
            Token::STRING,
            $start,
            $this->characterPosition,
            $line,
            $col,
            $prev,
            $value
        );
    }

    /**
     * Assert that a code is a valid character code.
     *
     * @param $code
     * @param $position
     * @throws SyntaxError
     */
    private function assertValidStringCharacterCode($code, $position)
    {
        // SourceCharacter
        if ($code < 0x0020 && $code !== 0x0009) {
            throw new SyntaxError(
                $this->source,
                $position,
                'Invalid character within String: ' . Utils::printCharCode($code)
            );
        }
    }

    /**
     * Increments the buffer position until it finds a non-whitespace
     * or commented character
     *
     * @return void
     */
    private function readWhitespace()
    {
        while (!$this->isEOF) {
            $code = $this->currentCharacter;

            if ($code === 10) { // new line
                $this->line++;
                $this->lineStart = $this->characterPosition + 1;
            } else if ($code === 13) { // carriage return
                if ($this->peekByte() !== 10) {
                    $this->line++;
                    $this->lineStart = $this->characterPosition + 1;
                }
              // Skip whitespace
              // tab | space | comma | BOM
            } else if ($code !== 9 && $code !== 32 && $code !== 44 && $code !== 0xFEFF) {
                break;
            }
            $this->readCharacter();
        }
    }

    /**
     * Reads a comment token from the source file.
     *
     * #[\u0009\u0020-\uFFFF]*
     *
     * @param $start
     * @param $line
     * @param $col
     * @param Token $prev
     * @return Token
     */
    private function readComment($start, $line, $col, Token $prev)
    {
        $value = '';

        while (
            ($code = $this->readCharacter()) &&
            ($code > 0x001F || $code === 0x0009)
         ) {
            $value .= chr($code);
        }

        return new Token(
            Token::COMMENT,
            $start,
            $this->characterPosition,
            $line,
            $col,
            $prev,
            $value
        );
    }

    private function readCharacter() {
        if ($this->position >= $this->source->length) {
          if (!$this->isEOF) {
            $this->isEOF = true;
            $this->currentCharacter = '<EOF>';
            $this->position++;
            $this->characterPosition++;
          }

          return '<EOF>';
        }

        $body = $this->source->body;
        $byte = ord($this->source->body[$this->position]);
        $ord = 0;

        if ($byte < 0x80) {
          $ord = ord($body[$this->position++]);
        }

        elseif (($byte & 0xE0) === 0xC0 ) {
          $ord =  (ord($body[$this->position++]) & 0x1F) << 6;
          $ord |= (ord($body[$this->position++]) & 0x3F);
        }

        elseif (($byte & 0xF0) === 0xE0 ) {
          $ord =  (ord($body[$this->position++]) & 0x0F) << 12;
          $ord |= (ord($body[$this->position++]) & 0x3F) << 6;
          $ord |= (ord($body[$this->position++]) & 0x3F);
        }

        elseif (($byte & 0xF8) === 0xF0 ) {
          $ord =  (ord($body[$this->position++]) & 0x07) << 18;
          $ord |= (ord($body[$this->position++]) & 0x3F) << 12;
          $ord |= (ord($body[$this->position++]) & 0x3F) << 6;
          $ord |= (ord($body[$this->position++]) & 0x3F);
        }

        $this->currentCharacter = $ord;
        $this->characterPosition++;
        return $this->currentCharacter;
    }

    private function peekByte($lookAhead = 0) {
        if ($this->position >= $this->source->length) {
            return '<EOF>';
        }

        return ord($this->source->body[$this->position + $lookAhead]);
    }
}
