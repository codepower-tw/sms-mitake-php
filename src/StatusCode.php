<?php

declare(strict_types=1);

namespace CodePower\Mitake;

/**
 * A Mitake status code (the `statuscode` / StatusFlag field).
 *
 * Numeric codes describe the delivery lifecycle; letter codes (and `*`)
 * describe send/account errors. See the Mitake API appendices.
 */
final class StatusCode
{
    /** Delivery lifecycle codes (appendix 1 / 3). */
    public const SCHEDULED = '0';        // 預約傳送中
    public const SENT_TO_CARRIER_1 = '1'; // 已送達業者
    public const SENT_TO_CARRIER_2 = '2'; // 已送達業者
    public const DELIVERED = '4';        // 已送達手機
    public const CONTENT_ERROR = '5';    // 內容有錯誤
    public const BAD_NUMBER = '6';       // 門號有錯誤
    public const DISABLED = '7';         // 簡訊已停用
    public const EXPIRED = '8';          // 逾時無送達
    public const CANCELLED = '9';        // 預約已取消

    /** @var array<string,string> Traditional Chinese (Taiwan) descriptions. */
    private const DESCRIPTIONS = [
        '0' => '預約傳送中',
        '1' => '已送達業者',
        '2' => '已送達業者',
        '4' => '已送達手機',
        '5' => '內容有錯誤',
        '6' => '門號有錯誤',
        '7' => '簡訊已停用',
        '8' => '逾時無送達',
        '9' => '預約已取消',
        '*' => '系統發生錯誤，請聯絡三竹資訊窗口人員',
        'a' => '簡訊發送功能暫時停止服務，請稍候再試',
        'b' => '簡訊發送功能暫時停止服務，請稍候再試',
        'c' => '請輸入帳號',
        'd' => '請輸入密碼',
        'e' => '帳號、密碼錯誤',
        'f' => '帳號已過期',
        'h' => '帳號已被停用',
        'k' => '無效的連線位址',
        'l' => '帳號已達到同時連線數上限',
        'm' => '必須變更密碼，在變更密碼前，無法使用簡訊發送服務',
        'n' => '密碼已逾期，在變更密碼前，將無法使用簡訊發送服務',
        'p' => '沒有權限使用外部Http程式',
        'r' => '系統暫停服務，請稍後再試',
        's' => '帳務處理失敗，無法發送簡訊',
        't' => '簡訊已過期',
        'u' => '簡訊內容不得為空白',
        'v' => '無效的手機號碼',
        'w' => '查詢筆數超過上限',
        'x' => '發送檔案過大，無法發送簡訊',
        'y' => '參數錯誤',
        'z' => '查無資料',
    ];

    public function __construct(public readonly string $code)
    {
    }

    /** A letter code (or `*`) means a send/account error. */
    public function isError(): bool
    {
        return $this->code !== '' && !ctype_digit($this->code);
    }

    /** Accepted/queued/in-transit but not yet delivered (0, 1, 2). */
    public function isPending(): bool
    {
        return in_array($this->code, [self::SCHEDULED, self::SENT_TO_CARRIER_1, self::SENT_TO_CARRIER_2], true);
    }

    /** Delivered to the handset (4). */
    public function isDelivered(): bool
    {
        return $this->code === self::DELIVERED;
    }

    /** A terminal delivery failure (5, 6, 7, 8). */
    public function isFailed(): bool
    {
        return in_array($this->code, [self::CONTENT_ERROR, self::BAD_NUMBER, self::DISABLED, self::EXPIRED], true);
    }

    /** A scheduled message that was cancelled (9). */
    public function isCancelled(): bool
    {
        return $this->code === self::CANCELLED;
    }

    /** Human-readable description (Traditional Chinese), or null if unknown. */
    public function description(): ?string
    {
        return self::DESCRIPTIONS[$this->code] ?? null;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
