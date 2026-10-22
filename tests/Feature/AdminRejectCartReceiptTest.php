<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\TelegramBotController;
use App\Models\Payment;
use App\Services\Telegram;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

class AdminRejectCartReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['TELEGRAM_BOT_TOKEN'] = 'test-token';
        $_SERVER['TELEGRAM_BOT_TOKEN'] = 'test-token';
        putenv('TELEGRAM_BOT_TOKEN=test-token');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('tel_id')->unique();
            $table->string('is_admin')->default('0');
            $table->string('is_seller')->default('0');
            $table->string('path')->default('start');
            $table->string('username')->nullable();
            $table->string('parent')->default('0');
            $table->integer('balance')->default(0);
            $table->integer('status')->default(1);
            $table->json('tel_detail')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('method')->nullable();
            $table->string('ref_id')->nullable();
            $table->string('type')->nullable();
            $table->integer('price')->nullable();
            $table->integer('status')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('cart')->nullable();
            $table->string('sheba')->nullable();
            $table->integer('admin_id')->nullable();
            $table->integer('is_default')->nullable()->default(-1);
            $table->integer('status')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_data', function (Blueprint $table) {
            $table->id();
            $table->string('tel_id')->nullable();
            $table->json('data')->nullable();
            $table->text('path')->nullable();
            $table->text('types')->nullable();
            $table->timestamps();
        });
    }

    public function test_admin_can_reject_a_pending_receipt_from_a_channel_callback(): void
    {
        DB::table('users')->insert([
            [
                'id' => 1,
                'tel_id' => '1001',
                'first_name' => 'Admin',
                'username' => 'admin',
                'is_admin' => '1',
                'is_seller' => '0',
                'path' => 'start',
                'status' => 1,
            ],
            [
                'id' => 2,
                'tel_id' => '2002',
                'first_name' => 'Customer',
                'username' => 'customer',
                'is_admin' => '0',
                'is_seller' => '0',
                'path' => 'start',
                'status' => 1,
            ],
        ]);

        DB::table('payments')->insert([
            'id' => 9475,
            'user_id' => 2,
            'method' => 'cart-be-cart',
            'type' => '1',
            'price' => 600000,
            'status' => 0,
            'detail' => json_encode([
                'cart-number' => '6219861437345936',
                'cart-sheba' => 'IR820540102680020817909002',
                'cart-name' => 'ضیایی',
            ]),
        ]);

        $channelId = -1001234567890;
        $request = Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-1',
                'from' => [
                    'id' => 1001,
                    'first_name' => 'Admin',
                    'username' => 'admin',
                ],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => $channelId, 'type' => 'channel'],
                    'photo' => [['file_id' => 'receipt']],
                    'caption' => 'رسید جدید کارت به کارت',
                ],
                'data' => 'type=adminRejectCartReceipt|p_id=9475',
            ],
        ]);

        $controller = new TelegramBotController($request);
        $telegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($controller, 'telegramSdk');
        $telegramProperty->setValue($controller, $telegram);

        $controller->index();

        $payment = Payment::findOrFail(9475);
        $this->assertSame(-1, (int) $payment->status);
        $this->assertSame(1, (int) $payment->admin_id);
        $this->assertFalse(DB::table('users')->where('tel_id', (string) $channelId)->exists());

        $this->assertSame('callback-1', $telegram->answeredCallbacks[0]['callback_query_id']);
        $this->assertSame($channelId, $telegram->editedCaptions[0]['chat_id']);
        $this->assertStringContainsString('وضعیت: ❌ رد شده', $telegram->editedCaptions[0]['caption']);
        $this->assertStringContainsString('IR820540102680020817909002', $telegram->editedCaptions[0]['caption']);
        $this->assertSame(['inline_keyboard' => []], json_decode($telegram->editedCaptions[0]['reply_markup'], true));
        $this->assertSame('2002', (string) $telegram->sentMessages[0]['chat_id']);
        $this->assertStringContainsString('پرداخت شما تایید نشد', $telegram->sentMessages[0]['text']);

        $duplicateController = new TelegramBotController(Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-duplicate',
                'from' => ['id' => 1001, 'first_name' => 'Admin', 'username' => 'admin'],
                'message' => [
                    'message_id' => 78,
                    'chat' => ['id' => $channelId, 'type' => 'channel'],
                    'photo' => [['file_id' => 'duplicate-receipt']],
                    'caption' => 'نسخه تکراری رسید',
                ],
                'data' => 'type=adminRejectCartReceipt|p_id=9475',
            ],
        ]));
        $duplicateTelegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($duplicateController, 'telegramSdk');
        $telegramProperty->setValue($duplicateController, $duplicateTelegram);
        $duplicateController->index();

        $this->assertCount(1, $duplicateTelegram->editedCaptions);
        $this->assertCount(0, $duplicateTelegram->sentMessages);
        $this->assertStringContainsString('قبلاً رد شده بود', $duplicateTelegram->answeredCallbacks[0]['text']);
    }

    public function test_non_admin_cannot_reject_a_receipt_from_the_channel(): void
    {
        DB::table('users')->insert([
            'id' => 2,
            'tel_id' => '2002',
            'first_name' => 'Customer',
            'is_admin' => '0',
            'is_seller' => '0',
            'path' => 'start',
            'status' => 1,
        ]);

        DB::table('payments')->insert([
            'id' => 9475,
            'user_id' => 2,
            'method' => 'cart-be-cart',
            'type' => '1',
            'price' => 600000,
            'status' => 0,
            'detail' => json_encode([]),
        ]);

        $request = Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-2',
                'from' => ['id' => 2002, 'first_name' => 'Customer'],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => -1001234567890, 'type' => 'channel'],
                    'text' => 'رسید جدید کارت به کارت',
                ],
                'data' => 'type=adminRejectCartReceipt|p_id=9475',
            ],
        ]);

        $controller = new TelegramBotController($request);
        $telegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($controller, 'telegramSdk');
        $telegramProperty->setValue($controller, $telegram);

        $controller->index();

        $payment = Payment::findOrFail(9475);
        $this->assertSame(0, (int) $payment->status);
        $this->assertNull($payment->admin_id);
        $this->assertSame('دسترسی غیرمجاز است.', $telegram->answeredCallbacks[0]['text']);
        $this->assertSame([], $telegram->editedCaptions);
        $this->assertSame([], $telegram->sentMessages);
    }

    public function test_cart_payment_shows_and_persists_the_optional_sheba_number(): void
    {
        DB::table('users')->insert([
            'id' => 2,
            'tel_id' => '2002',
            'first_name' => 'Customer',
            'is_admin' => '0',
            'is_seller' => '0',
            'path' => 'start',
            'status' => 1,
        ]);
        DB::table('settings')->insert([
            [
                'key' => 'cart_be_cart_random',
                'name' => 'Random card',
                'value' => '0',
            ],
            [
                'key' => 'support_id',
                'name' => 'Support',
                'value' => 'support',
            ],
        ]);
        DB::table('carts')->insert([
            'id' => 10,
            'name' => 'ضیایی',
            'cart' => '6219861437345936',
            'sheba' => 'IR820540102680020817909002',
            'is_default' => 1,
            'status' => 1,
        ]);
        DB::table('payments')->insert([
            'id' => 9475,
            'user_id' => 2,
            'method' => null,
            'type' => '1',
            'price' => 600000,
            'status' => 0,
            'detail' => json_encode([]),
        ]);

        $request = Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-payment',
                'from' => ['id' => 2002, 'first_name' => 'Customer'],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => 2002, 'type' => 'private'],
                    'text' => 'پرداخت سفارش',
                ],
                'data' => 'type=paymentCartBeCart|id=9475',
            ],
        ]);

        $controller = new TelegramBotController($request);
        $telegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($controller, 'telegramSdk');
        $telegramProperty->setValue($controller, $telegram);

        $controller->index();

        $this->assertStringContainsString(
            '💳 شماره کارت: <code>6219861437345936</code>',
            $telegram->editedMessages[0]['text']
        );
        $this->assertStringContainsString('شماره شبا', $telegram->editedMessages[0]['text']);
        $this->assertStringContainsString('IR820540102680020817909002', $telegram->editedMessages[0]['text']);
        $this->assertSame(
            'IR820540102680020817909002',
            Payment::findOrFail(9475)->detail['cart-sheba']
        );
    }

    public function test_admin_can_save_a_sheba_number_from_the_card_edit_flow(): void
    {
        DB::table('users')->insert([
            'id' => 1,
            'tel_id' => '1001',
            'first_name' => 'Admin',
            'is_admin' => '1',
            'is_seller' => '0',
            'path' => 'start',
            'status' => 1,
        ]);
        DB::table('carts')->insert([
            'id' => 10,
            'name' => 'ضیایی',
            'cart' => '6219861437345936',
            'sheba' => null,
            'is_default' => 1,
            'status' => 1,
        ]);

        $editController = new TelegramBotController(Request::create('/api/telegram-webhook', 'POST', [
            'callback_query' => [
                'id' => 'callback-edit-sheba',
                'from' => ['id' => 1001, 'first_name' => 'Admin'],
                'message' => [
                    'message_id' => 77,
                    'chat' => ['id' => 1001, 'type' => 'private'],
                    'text' => 'جزئیات کارت',
                ],
                'data' => 'type=adminCartEdit|id=10|key=sheba',
            ],
        ]));
        $editTelegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($editController, 'telegramSdk');
        $telegramProperty->setValue($editController, $editTelegram);
        $editController->index();

        $saveController = new TelegramBotController(Request::create('/api/telegram-webhook', 'POST', [
            'message' => [
                'message_id' => 78,
                'from' => ['id' => 1001, 'first_name' => 'Admin'],
                'chat' => ['id' => 1001, 'type' => 'private'],
                'text' => 'IR460560611828006296753301',
            ],
        ]));
        $saveTelegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($saveController, 'telegramSdk');
        $telegramProperty->setValue($saveController, $saveTelegram);
        $saveController->index();

        $this->assertSame(
            'IR460560611828006296753301',
            DB::table('carts')->where('id', 10)->value('sheba')
        );
        $this->assertStringContainsString('با موفقیت بروزرسانی شد', $saveTelegram->sentMessages[0]['text']);
        $this->assertSame('start', DB::table('users')->where('id', 1)->value('path'));
    }

    public function test_a_user_cannot_submit_more_than_one_receipt_for_the_same_payment(): void
    {
        DB::table('users')->insert([
            'id' => 2,
            'tel_id' => '2002',
            'first_name' => 'Customer',
            'is_admin' => '0',
            'is_seller' => '0',
            'path' => 'sendCartBeCartReceipt',
            'status' => 1,
            'tel_detail' => json_encode([
                'payment-id' => 9475,
                'payment-type' => 'cart-be-cart',
                'payment-cart-number' => '6219861437345936',
                'payment-cart-sheba' => 'IR820540102680020817909002',
                'payment-cart-name' => 'ضیایی',
            ]),
        ]);
        DB::table('settings')->insert([
            'key' => 'cart_be_cart_id',
            'name' => 'Receipt channel',
            'value' => '-1001234567890',
        ]);
        DB::table('payments')->insert([
            'id' => 9475,
            'user_id' => 2,
            'method' => 'cart-be-cart',
            'type' => '4',
            'price' => 600000,
            'status' => 0,
            'detail' => json_encode([
                'cart-number' => '6219861437345936',
                'cart-sheba' => 'IR820540102680020817909002',
                'cart-name' => 'ضیایی',
            ]),
        ]);

        $firstController = new TelegramBotController($this->receiptPhotoRequest('receipt-1'));
        $firstTelegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($firstController, 'telegramSdk');
        $telegramProperty->setValue($firstController, $firstTelegram);
        $firstController->index();

        $payment = Payment::findOrFail(9475);
        $this->assertSame('submitted', $payment->detail['receipt_submission_state']);
        $this->assertNotEmpty($payment->detail['receipt_submitted_at']);
        $this->assertCount(1, $firstTelegram->sentPhotos);
        $this->assertSame(-1001234567890, (int) $firstTelegram->sentPhotos[0]['chat_id']);
        $this->assertStringContainsString('IR820540102680020817909002', $firstTelegram->sentPhotos[0]['caption']);
        $this->assertSame('sendCartBeCartReceipt', DB::table('users')->where('id', 2)->value('path'));

        $secondController = new TelegramBotController($this->receiptPhotoRequest('receipt-2'));
        $secondTelegram = new FakeTelegramForReceiptRejection();
        $telegramProperty = new ReflectionProperty($secondController, 'telegramSdk');
        $telegramProperty->setValue($secondController, $secondTelegram);
        $secondController->index();

        $this->assertCount(0, $secondTelegram->downloadedFiles);
        $this->assertCount(0, $secondTelegram->sentPhotos);
        $this->assertStringContainsString('رسید این تراکنش قبلاً ارسال شده است', $secondTelegram->sentMessages[0]['text']);
        $this->assertStringContainsString('در حال بررسی', $secondTelegram->sentMessages[0]['text']);
    }

    private function receiptPhotoRequest(string $callbackId): Request
    {
        return Request::create('/api/telegram-webhook', 'POST', [
            'message' => [
                'message_id' => random_int(100, 999),
                'from' => ['id' => 2002, 'first_name' => 'Customer'],
                'chat' => ['id' => 2002, 'type' => 'private'],
                'photo' => [
                    ['file_id' => 'small', 'file_unique_id' => 'small'],
                    ['file_id' => 'medium', 'file_unique_id' => 'medium'],
                    ['file_id' => $callbackId, 'file_unique_id' => $callbackId],
                ],
            ],
        ]);
    }
}

class FakeTelegramForReceiptRejection extends Telegram
{
    public array $answeredCallbacks = [];
    public array $editedCaptions = [];
    public array $editedMessages = [];
    public array $sentMessages = [];
    public array $sentPhotos = [];
    public array $downloadedFiles = [];

    public function __construct()
    {
    }

    public function answerCallback(array $params)
    {
        $this->answeredCallbacks[] = $params;

        return ['ok' => true];
    }

    public function editCaption(array $params)
    {
        $this->editedCaptions[] = $params;

        return ['ok' => true];
    }

    public function editMessage(array $params)
    {
        $this->editedMessages[] = $params;

        return ['ok' => true, 'result' => ['message_id' => $params['message_id'] ?? 1]];
    }

    public function sendMessage(array $params)
    {
        $this->sentMessages[] = $params;

        return ['ok' => true, 'result' => ['message_id' => 1]];
    }

    public function sendPhoto(array $params)
    {
        $this->sentPhotos[] = $params;

        return ['ok' => true, 'result' => ['message_id' => 2]];
    }

    public function downloadFileById(string $fileId, string $savePath): array
    {
        $this->downloadedFiles[] = compact('fileId', 'savePath');

        return ['ok' => true];
    }
}
