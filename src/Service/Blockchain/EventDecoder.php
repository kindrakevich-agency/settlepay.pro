<?php

namespace App\Service\Blockchain;

/**
 * Decode ERC-20 Transfer events from a JSON-RPC log object.
 *
 *   event Transfer(address indexed from, address indexed to, uint256 value)
 *   keccak256("Transfer(address,address,uint256)")
 *     = 0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef
 *
 * Layout in the log:
 *   topics[0] = signature (above)
 *   topics[1] = from (32-byte left-padded address)
 *   topics[2] = to   (32-byte left-padded address)
 *   data      = value (32-byte uint256, big-endian)
 *
 * This class does no chain I/O — it just parses the bytes.
 */
final class EventDecoder
{
    public const TRANSFER_SIGNATURE = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /**
     * Returns null if the log is not a Transfer or is malformed.
     * Otherwise returns:
     *   [
     *     'from'             => '0x...' (lower-case 20-byte address),
     *     'to'               => '0x...' (lower-case 20-byte address),
     *     'value'            => '12345' (decimal string, arbitrary precision),
     *     'token_address'    => '0x...' (lower-case),
     *     'block_number'     => 12345 (int),
     *     'tx_hash'          => '0x...' (lower-case),
     *     'log_index'        => 0 (int),
     *   ]
     *
     * @param array{address?:string,topics?:list<string>,data?:string,blockNumber?:string,transactionHash?:string,logIndex?:string} $log
     */
    public function decodeTransfer(array $log): ?array
    {
        $topics = $log['topics'] ?? [];
        if (count($topics) !== 3) return null;
        if (strtolower($topics[0]) !== self::TRANSFER_SIGNATURE) return null;

        $tokenAddress = strtolower((string)($log['address'] ?? ''));
        if (!preg_match('/^0x[0-9a-f]{40}$/', $tokenAddress)) return null;

        return [
            'from'          => RpcClient::topicToAddress($topics[1]),
            'to'            => RpcClient::topicToAddress($topics[2]),
            'value'         => self::hexToDecimal((string)($log['data'] ?? '0x0')),
            'token_address' => $tokenAddress,
            'block_number'  => (int) hexdec(ltrim((string)($log['blockNumber'] ?? '0x0'), '0x')),
            'tx_hash'       => strtolower((string)($log['transactionHash'] ?? '')),
            'log_index'     => (int) hexdec(ltrim((string)($log['logIndex'] ?? '0x0'), '0x')),
        ];
    }

    /**
     * Convert a 0x-prefixed hex string to an arbitrary-precision decimal
     * string using bcmath. PHP's intval()/hexdec() lose precision past
     * PHP_INT_MAX, which we pass for any USDC payment over ~9e15 base
     * units (= $9 billion); this implementation has no upper bound.
     */
    public static function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0x');
        if ($hex === '' || $hex === '0') return '0';

        $dec = '0';
        for ($i = 0, $len = strlen($hex); $i < $len; $i++) {
            $digit = strpos('0123456789abcdef', $hex[$i]);
            if ($digit === false) {
                throw new \InvalidArgumentException("Invalid hex character: {$hex[$i]}");
            }
            $dec = bcadd(bcmul($dec, '16'), (string) $digit);
        }
        return $dec;
    }
}
