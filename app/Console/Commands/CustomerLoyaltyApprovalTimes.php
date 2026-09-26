<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Import only independently verified review timestamps; never infer them from updated_at. */
class CustomerLoyaltyApprovalTimes extends Command
{
    protected $signature = 'loyalty:approval-times {file? : Reviewed CSV: payment_id,user_id,amount,approved_at,evidence} {--apply : Write metadata after validating the entire file}';
    protected $description = 'Audit missing approval times or preview/import verified timestamps (no balance/amount/status writes)';

    public function handle(): int
    {
        if (!$this->argument('file')) {
            $missing = 0;
            foreach (Payment::where('status', 1)->whereIn('method', ['cart-be-cart','admin_credit','admin_debit'])->cursor() as $p) {
                if (empty($p->detail['loyalty_approved_at'])) $missing++;
            }
            $this->info('Approved original payments without a verified approval time: '.$missing);
            return self::SUCCESS;
        }
        try {
            $file = fopen($this->argument('file'), 'r');
            if (!$file) throw new RuntimeException('Cannot open CSV.');
            $header = fgetcsv($file);
            if ($header !== ['payment_id','user_id','amount','approved_at','evidence']) throw new RuntimeException('Invalid CSV header.');
            $rows = []; $ids = [];
            while (($values = fgetcsv($file)) !== false) {
                if (count($values) !== 5) throw new RuntimeException('Invalid CSV row.');
                $r = array_combine($header, $values);
                foreach (['payment_id','user_id','amount'] as $key) if (!ctype_digit($r[$key]) || (int) $r[$key] <= 0) throw new RuntimeException('Invalid '.$key);
                if (isset($ids[$r['payment_id']])) throw new RuntimeException('Duplicate payment ID.');
                $ids[$r['payment_id']] = true;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $r['approved_at'])) throw new RuntimeException('Use an ISO timestamp with timezone.');
                $at = CarbonImmutable::parse($r['approved_at']);
                if ($at->isFuture() || trim($r['evidence']) === '') throw new RuntimeException('Future time or missing evidence.');
                $r['approved_at'] = $at->toIso8601String(); $rows[] = $r;
            }
            fclose($file);
            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    $p = Payment::whereKey($row['payment_id'])->lockForUpdate()->first();
                    if (!$p || (int) $p->status !== 1 || (int) $p->user_id !== (int) $row['user_id'] || (int) $p->price !== (int) $row['amount']
                        || !in_array($p->method, ['cart-be-cart','admin_credit','admin_debit'], true)
                        || !empty($p->detail['receipt_id']) || ($p->created_at && CarbonImmutable::parse($row['approved_at'])->lt($p->created_at))) {
                        throw new RuntimeException('Payment does not match verified evidence: '.$row['payment_id']);
                    }
                    $detail = $p->detail ?? [];
                    if (!empty($detail['loyalty_approved_at'])) {
                        if (CarbonImmutable::parse($detail['loyalty_approved_at'])->equalTo(CarbonImmutable::parse($row['approved_at']))) continue;
                        throw new RuntimeException('Refusing to replace existing approval time: '.$p->id);
                    }
                    if ($this->option('apply')) {
                        $detail['loyalty_approved_at'] = $row['approved_at'];
                        $detail['loyalty_approval_evidence'] = $row['evidence'];
                        // Avoid touching updated_at, wallet balances or financial fields.
                        DB::table('payments')->where('id', $p->id)->update(['detail' => json_encode($detail)]);
                    }
                }
            });
            $this->info(count($rows).' rows validated'.($this->option('apply') ? ' and applied.' : '; dry run only.'));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
