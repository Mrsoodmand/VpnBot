<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Orders;
use App\Services\OrderCountryResolver;
use App\Services\OrderCountryService;
use Illuminate\Support\Facades\Log;

trait ManagesOrderCountry
{
    protected function adminOrderCountry(array $data)
    {
        if (! $this->isAdmin) {
            return $this->denyAdminAccess();
        }
        $service = app(OrderCountryService::class);
        $actor = ['source' => 'telegram', 'id' => (string) $this->user->id, 'name' => (string) ($this->user->username ?: $this->chatId)];
        $id = (int) ($data['id'] ?? 0);
        $buttons = [];
        $escape = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        try {
            if (($data['a'] ?? '') === 'apply') {
                $order = $service->confirm((string) ($data['t'] ?? ''), $actor);
                $id = $order->id;
                $message = headTitle('✅ کشور سرویس تغییر کرد').$escape($order->detail['current_country']['label'])."\nتنظیمات اشتراک را در برنامهٔ اتصال به‌روزرسانی کنید.";
                if (($order->detail['country_site_sync']['ok'] ?? true) === false) {
                    $message .= "\n⚠️ ثبت در سایت تأیید نشد؛ برای تکرار همگام‌سازی، همین کشور را دوباره تأیید کنید.";
                }
            } else {
                $order = Orders::botManaged()->findOrFail($id);
                if (($data['a'] ?? '') === 'history') {
                    $history = array_reverse($order->detail['country_history'] ?? []);
                    $page = max(0, min((int) ($data['p'] ?? 0), max(0, (int) ceil(count($history) / 5) - 1)));
                    $message = headTitle('📋 سابقهٔ تغییر کشور');
                    foreach (array_slice($history, $page * 5, 5) as $entry) {
                        $message .= $escape(($entry['from'] ?: 'نامشخص').' ← '.$entry['to'])."\n";
                        $message .= $escape(($entry['actor']['name'] ?? $entry['actor']['source'] ?? '').' | '.$entry['at'])."\n\n";
                    }
                    if (! $history) {
                        $message .= 'تغییری ثبت نشده است.';
                    }
                    $nav = [];
                    if ($page > 0) {
                        $nav[] = ['text' => 'قبلی', 'callback_data' => 'type=adminOC|a=history|id='.$id.'|p='.($page - 1)];
                    }
                    if (($page + 1) * 5 < count($history)) {
                        $nav[] = ['text' => 'بعدی', 'callback_data' => 'type=adminOC|a=history|id='.$id.'|p='.($page + 1)];
                    }
                    if ($nav) {
                        $buttons[] = $nav;
                    }
                } elseif (($data['a'] ?? '') === 'select') {
                    $preview = $service->preview($order, (int) ($data['c'] ?? -1), $actor);
                    $message = headTitle('🌍 تأیید تغییر کشور').'کشور فعلی: '.$escape($preview['from'])."\nکشور جدید: ".$escape($preview['to'])."\nحجم، زمان و مبلغ پرداخت‌شده حفظ می‌شوند.";
                    $buttons[] = [['text' => '✅ تأیید تغییر کشور', 'callback_data' => 'type=adminOC|a=apply|t='.$preview['token']]];
                } else {
                    $options = $service->options($order);
                    $message = headTitle('🌍 تغییر کشور سفارش').'کشور فعلی: '.$escape(app(OrderCountryResolver::class)->resolve([$order])[$id])."\nکشور جدید را انتخاب کنید.";
                    $singles = array_values(array_filter($options, fn ($option) => $option['country_id'] > 0));
                    $page = max(0, min((int) ($data['p'] ?? 0), max(0, (int) ceil(count($singles) / 16) - 1)));
                    foreach (array_chunk(array_slice($singles, $page * 16, 16), 2) as $row) {
                        $buttons[] = array_map(fn ($option) => ['text' => $option['label'], 'callback_data' => "type=adminOC|a=select|id={$id}|c={$option['country_id']}"], $row);
                    }
                    foreach ($options as $option) {
                        if ($option['country_id'] === 0) {
                            $buttons[] = [['text' => $option['label'], 'callback_data' => "type=adminOC|a=select|id={$id}|c=0"]];
                        }
                    }
                    $nav = [];
                    if ($page > 0) {
                        $nav[] = ['text' => 'قبلی', 'callback_data' => 'type=adminOC|id='.$id.'|p='.($page - 1)];
                    }
                    if (($page + 1) * 16 < count($singles)) {
                        $nav[] = ['text' => 'بعدی', 'callback_data' => 'type=adminOC|id='.$id.'|p='.($page + 1)];
                    }
                    if ($nav) {
                        $buttons[] = $nav;
                    }
                    if (! $options) {
                        $message .= "\nکشور فعال روی پنل این سفارش پیدا نشد.";
                    }
                    if (! empty($order->detail['country_history'])) {
                        $buttons[] = [['text' => '📋 سابقهٔ تغییر کشور', 'callback_data' => "type=adminOC|a=history|id={$id}"]];
                    }
                }
            }
        } catch (\RuntimeException $exception) {
            $message = headTitle('⚠️ تغییر کشور انجام نشد').$escape($exception->getMessage());
        } catch (\Throwable $exception) {
            Log::warning('Order country action failed', ['order_id' => $id, 'error' => $exception->getMessage()]);
            $message = headTitle('⚠️ بررسی تغییر کشور').'نتیجهٔ عملیات مشخص نشد؛ صفحهٔ سفارش را دوباره باز کنید و کشور فعلی را بررسی کنید.';
        }
        $buttons[] = [['text' => '🔙 جزئیات سفارش', 'callback_data' => $id ? "type=adminOrderSingle|id={$id}" : 'type=adminOrdersList']];

        return $this->sendMessage(['chat_id' => $this->chatId, 'message_id' => $this->messageId, 'text' => $message,
            'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $buttons])], 'message');
    }
}
