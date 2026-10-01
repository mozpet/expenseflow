<?php

namespace App\Services\Payroll\Formula;

/**
 * Parser recursive-descent untuk DSL formula komponen gaji.
 *
 * Mengubah deret token {@see FormulaLexer} menjadi Abstract Syntax Tree (AST)
 * berupa array bersarang (JSON-serializable, mudah di-debug & disimpan sebagai
 * jejak). Presedensi operator (rendah → tinggi):
 *
 *   1. Perbandingan  ==  !=  <  <=  >  >=      (menghasilkan 1.0 / 0.0)
 *   2. Penjumlahan   +  -
 *   3. Perkalian     *  /
 *   4. Persen        x%                         (postfix, = x / 100)
 *   5. Unary         -x  +x
 *   6. Primer        angka | variabel | FUNGSI(...) | ( ... )
 *
 * Batas kedalaman rekursi ({@see MAX_DEPTH}) mencegah stack overflow / DoS dari
 * formula yang sengaja dibuat sangat bersarang.
 *
 * Bentuk node AST:
 *   ['type' => 'num',     'value' => float]
 *   ['type' => 'var',     'name'  => string]
 *   ['type' => 'neg',     'operand' => node]
 *   ['type' => 'percent', 'operand' => node]
 *   ['type' => 'bin',     'op' => '+|-|*|/', 'left' => node, 'right' => node]
 *   ['type' => 'cmp',     'op' => '==|!=|<|<=|>|>=', 'left' => node, 'right' => node]
 *   ['type' => 'call',    'name' => string, 'args' => node[]]
 */
class FormulaParser
{
    private const MAX_DEPTH = 64;

    /** Jenis token perbandingan → simbol operator. */
    private const COMPARATORS = [
        'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>=', 'eq' => '==', 'neq' => '!=',
    ];

    private int $pos = 0;
    private int $depth = 0;

    /** @param array<int, array{kind: string, value?: float|string, pos: int}> $tokens */
    public function __construct(private readonly array $tokens)
    {
    }

    /**
     * Urai token menjadi AST. Melempar {@see FormulaException} bila sintaks salah.
     *
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        if ($this->peek()['kind'] === 'eof') {
            throw new FormulaException('Formula kosong.', 0);
        }

        $node = $this->parseComparison();

        $tail = $this->peek();
        if ($tail['kind'] !== 'eof') {
            throw new FormulaException('Ada token tak terduga setelah akhir ekspresi.', $tail['pos']);
        }

        return $node;
    }

    // ─── Level presedensi ───────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function parseComparison(): array
    {
        $this->enter();
        $node = $this->parseAdditive();

        while (isset(self::COMPARATORS[$this->peek()['kind']])) {
            $op = self::COMPARATORS[$this->next()['kind']];
            $node = ['type' => 'cmp', 'op' => $op, 'left' => $node, 'right' => $this->parseAdditive()];
        }

        $this->leave();

        return $node;
    }

    /** @return array<string, mixed> */
    private function parseAdditive(): array
    {
        $node = $this->parseTerm();

        while (in_array($this->peek()['kind'], ['plus', 'minus'], true)) {
            $op = $this->next()['kind'] === 'plus' ? '+' : '-';
            $node = ['type' => 'bin', 'op' => $op, 'left' => $node, 'right' => $this->parseTerm()];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function parseTerm(): array
    {
        $node = $this->parseFactor();

        while (in_array($this->peek()['kind'], ['star', 'slash'], true)) {
            $op = $this->next()['kind'] === 'star' ? '*' : '/';
            $node = ['type' => 'bin', 'op' => $op, 'left' => $node, 'right' => $this->parseFactor()];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function parseFactor(): array
    {
        $node = $this->parseUnary();

        // Postfix persen: 5% → 0,05. Hanya satu kali (5%% dilarang).
        if ($this->peek()['kind'] === 'percent') {
            $this->next();
            $node = ['type' => 'percent', 'operand' => $node];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function parseUnary(): array
    {
        $kind = $this->peek()['kind'];

        if ($kind === 'minus') {
            $this->next();

            return ['type' => 'neg', 'operand' => $this->parseUnary()];
        }
        if ($kind === 'plus') {
            $this->next(); // unary plus = no-op

            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    /** @return array<string, mixed> */
    private function parsePrimary(): array
    {
        $this->enter();
        $token = $this->next();

        switch ($token['kind']) {
            case 'number':
                $node = ['type' => 'num', 'value' => (float) $token['value']];
                break;

            case 'lparen':
                $node = $this->parseComparison();
                $this->expect('rparen', 'Kurung tutup ")" tidak ditemukan.');
                break;

            case 'ident':
                if ($this->peek()['kind'] === 'lparen') {
                    // Pemanggilan fungsi: NAMA( arg1, arg2, ... )
                    $this->next(); // konsumsi '('
                    $args = [];
                    if ($this->peek()['kind'] !== 'rparen') {
                        $args[] = $this->parseComparison();
                        while ($this->peek()['kind'] === 'comma') {
                            $this->next();
                            $args[] = $this->parseComparison();
                        }
                    }
                    $this->expect('rparen', "Kurung tutup \")\" untuk fungsi {$token['value']}() tidak ditemukan.");
                    $node = ['type' => 'call', 'name' => (string) $token['value'], 'args' => $args];
                } else {
                    $node = ['type' => 'var', 'name' => (string) $token['value']];
                }
                break;

            default:
                throw new FormulaException('Token tak terduga saat mengurai nilai.', $token['pos']);
        }

        $this->leave();

        return $node;
    }

    // ─── Utilitas token ─────────────────────────────────────────────────

    /** @return array{kind: string, value?: float|string, pos: int} */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    /** @return array{kind: string, value?: float|string, pos: int} */
    private function next(): array
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(string $kind, string $message): void
    {
        if ($this->peek()['kind'] !== $kind) {
            throw new FormulaException($message, $this->peek()['pos']);
        }
        $this->next();
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new FormulaException('Formula terlalu kompleks / terlalu dalam bersarang.');
        }
    }

    private function leave(): void
    {
        $this->depth--;
    }
}
