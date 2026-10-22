<?php

namespace App\Services;

use App\Models\Countries;
use App\Models\ExtraBandwidth;
use App\Models\Orders;
use App\Models\Panels;
use App\Models\Payment;
use App\Models\Plans;
use App\Models\PreOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentReceiptFormatter
{
    /** Preserve the purchased selection when plans or services are edited later. */
    public function snapshot(Payment $payment): array
    {
        $detail = $payment->detail ?? [];
        if (is_array($detail['receipt_details'] ?? null)) {
            return $detail['receipt_details'];
        }

        $type = (string) $payment->type;
        $user = User::find($payment->user_id);
        $preOrder = $type === '1' && $payment->order_id
            ? PreOrder::where('user_id', $payment->user_id)->find($payment->order_id) : null;
        $order = in_array($type, ['2', '3'], true) && $payment->order_id
            ? Orders::where('user_id', $payment->user_id)->find($payment->order_id) : null;
        $selection = $preOrder?->data ?? [];
        $planId = match ($type) {
            '1' => $selection['plan-id'] ?? null,
            '2' => $detail['plan-id'] ?? null,
            '3' => $order?->plan,
            default => null,
        };
        $plan = $planId ? Plans::find($planId) : null;
        $panel = $order?->panel_id ? Panels::find($order->panel_id) : null;
        $serviceId = $selection['service-id'] ?? $panel?->panel_type ?? $plan?->type;
        $service = is_numeric($serviceId) && (int) $serviceId > 0
            ? Service::withTrashed()->find($serviceId) : null;
        $extra = $type === '3' && !empty($detail['extra-id']) ? ExtraBandwidth::find($detail['extra-id']) : null;

        $country = 'نامشخص';
        if ($order) {
            $country = app(OrderCountryResolver::class)->resolve([$order])[$order->id] ?? $country;
        } elseif (!empty($selection['pasarguard-id'])) {
            $country = '🌍 همه کشورها';
        } elseif (!empty($selection['country-id'])) {
            $country = Countries::whereKey($selection['country-id'])->value('name') ?: $country;
        }

        // Linking the website account is optional on older bot installations.
        $phone = Schema::hasTable('wp_sync_links')
            ? DB::table('wp_sync_links')->where('user_id', $payment->user_id)->value('phone') : null;

        return [
            'user' => $user?->username ? '@' . $user->username : (trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')) ?: 'بدون نام'),
            'tel_id' => $user?->tel_id ?? 'نامشخص',
            'phone' => $phone ?: 'متصل نشده',
            'service' => $service?->name ?: 'نامشخص',
            'plan' => $plan?->name ?: ($plan ? 'پلن ' . $plan->bandwidth . ' گیگ' : 'نامشخص'),
            'bandwidth' => $plan?->bandwidth,
            'days' => $plan?->days,
            'country' => $country,
            'quantity' => (int) ($preOrder?->count ?? $selection['count'] ?? 1),
            'extra_gb' => $extra?->name,
            'remark' => $detail['remark'] ?? $order?->remark,
        ];
    }

    /** HTML chunks stay within Telegram's caption/text limits, without dropping batch remarks. */
    public function parts(Payment $payment, string $state, int $limit = 1024): array
    {
        $limit = min($limit, 4000); // Leave room for a continuation heading on text receipts.
        $info = $this->snapshot($payment);
        $detail = $payment->detail ?? [];
        $type = (string) $payment->type;
        $status = match ($state) {
            'pending' => '⏳ در انتظار بررسی',
            'processing' => '⏳ پرداخت تایید شده؛ در حال تحویل سفارش به کاربر هستیم...',
            'approved' => '✅ تایید شده',
            'rejected' => '❌ رد شده',
            'refunded' => '↩️ مبلغ به کیف پول برگشت',
            default => '⚠️ پرداخت تایید شده؛ تحویل نیاز به بررسی دارد',
        };
        $typeLabel = match ($type) {
            '1' => 'خرید جدید ربات',
            '2' => 'تمدید سرویس ربات',
            '3' => 'خرید حجم ربات',
            '4' => 'شارژ کیف پول ربات',
            default => 'تراکنش ربات',
        };
        $lines = ['🧾 <b>رسید آی پی ثابت — ربات</b>', ''];
        $this->field($lines, 'نوع', $typeLabel);
        $this->field($lines, 'وضعیت', $status);
        // Payment ID is stable across retries; preorder IDs and service IDs can overlap.
        $this->field($lines, $type === '1' ? 'کد سفارش' : 'کد پیگیری', 'BOT-P' . $payment->id, true);
        if ($payment->order_id && in_array($type, ['2', '3'], true)) {
            $this->field($lines, 'کد سفارش', 'BOT-' . $payment->order_id, true);
        }
        $this->field($lines, 'کاربر', $info['user']);
        $this->field($lines, 'شناسه تلگرام', $info['tel_id'], true);
        $this->field($lines, 'موبایل', $info['phone']);
        if ($type !== '4') {
            $this->field($lines, $type === '2' ? 'پلن تمدید' : 'پلن', $info['plan']);
            $this->field($lines, 'کشور', $info['country']);
        }
        if ($type === '1') $this->field($lines, 'تعداد سرویس', $info['quantity']);
        if ($type === '3') $this->field($lines, 'حجم خریداری شده', $info['extra_gb'] !== null ? $info['extra_gb'] . ' گیگ' : 'نامشخص');
        $this->field($lines, 'مبلغ', number_format((float) $payment->price) . ' تومان');
        $this->field($lines, 'شناسه رسید', '#' . $payment->id, true);
        $this->field($lines, 'روش پرداخت', $payment->method === 'wallet' ? 'کیف پول' : 'کارت به کارت');
        if ($type === '4' && !empty($detail['wallet_credit_applied'])) {
            $this->field($lines, 'موجودی پس از شارژ', number_format((float) $detail['wallet_balance_after']) . ' تومان');
        }

        $remarks = [];
        if ($type === '1' && $payment->order_id && $state !== 'pending' && $state !== 'rejected') {
            $remarks = Orders::where('user_id', $payment->user_id)
                ->where('detail->preOrderId', $payment->order_id)->pluck('remark')->all();
        } elseif (!empty($info['remark'])) {
            $remarks = [$info['remark']];
        }
        if ($remarks) {
            $lines[] = '';
            $lines[] = $type === '1'
                ? ($state === 'approved' ? 'ریمارک تحویل داده شده:' : 'ریمارک ساخته شده (تحویل کامل نشده):')
                : 'ریمارک سرویس:';
            foreach ($remarks as $remark) $this->field($lines, '', $remark, true);
        }
        if ($state !== 'pending' && $payment->admin_id) {
            $admin = User::find($payment->admin_id);
            if ($admin) $this->field($lines, $state === 'rejected' ? 'رد شده توسط' : 'تایید شده توسط',
                $admin->username ? '@' . $admin->username : ($admin->first_name ?: 'ادمین'));
        }
        if (!empty($detail['cart-name'])) $this->field($lines, 'صاحب کارت', $detail['cart-name']);
        if ($state === 'needs_review' && !empty($detail['fulfillment']['message'])) {
            $this->field($lines, 'خطا', $detail['fulfillment']['message']);
        }
        if ($state === 'needs_review') $lines[] = 'پیش از تلاش مجدد، وضعیت سرویس در پنل بررسی شود.';
        if (($detail['receipt_is_photo'] ?? null) === false && !empty($detail['value'])) {
            $this->field($lines, 'رسید کاربر', $detail['value']);
        }

        $parts = [];
        $part = '';
        foreach ($lines as $line) {
            $candidate = $part === '' ? $line : $part . "\n" . $line;
            // UTF-16 units also account conservatively for emoji in Telegram entities.
            if ($part !== '' && $this->length($candidate) > $limit) {
                $parts[] = trim($part);
                $part = $line;
            } else {
                $part = $candidate;
            }
        }
        if (trim($part) !== '') $parts[] = trim($part);
        return $parts;
    }

    private function field(array &$lines, string $label, mixed $value, bool $code = false): void
    {
        // Escape after splitting so long user text cannot break an HTML entity or tag.
        $value = trim((string) $value);
        foreach (mb_str_split($value === '' ? '—' : $value, 350) as $index => $piece) {
            $escaped = htmlspecialchars($piece, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = ($label !== '' && $index === 0 ? $label . ': ' : '')
                . ($code ? '<code>' . $escaped . '</code>' : $escaped);
        }
    }

    private function length(string $html): int
    {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strlen(mb_convert_encoding($plain, 'UTF-16LE', 'UTF-8')) / 2;
    }
}
