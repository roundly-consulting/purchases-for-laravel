<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use RoundlyConsulting\Purchases\Actions\RecordPurchaseAction;
use RoundlyConsulting\Purchases\Actions\RecordRefundAction;
use RoundlyConsulting\Purchases\Actions\RecordSubscriptionAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/*
 * Each recording action lock-reads its (provider, provider_id) key before it inserts. On
 * MySQL that read takes a gap lock when the row does not exist yet, so two first deliveries
 * of one key (Stripe's checkout session and its payment intent share one by design) both pass
 * the read, both insert, and InnoDB kills one of them with a deadlock (1213). Laravel detects
 * that as a concurrency error and retries the transaction, when it is given attempts to do so.
 */
it('retries a first delivery that lost a deadlock', function (string $kind): void {
    $model = match ($kind) {
        'purchase' => PurchaseModel::class(),
        'subscription' => SubscriptionModel::class(),
        'refund' => PurchaseRefundModel::class(),
    };

    $attempts = 0;

    $model::creating(function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new QueryException('mysql', 'insert into …', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    $recorded = match ($kind) {
        'purchase' => app(RecordPurchaseAction::class)->execute(new RecordPurchaseData('stripe', 'pi_race', Status::Completed)),
        'subscription' => app(RecordSubscriptionAction::class)->execute(new RecordSubscriptionData('stripe', 'sub_race', Status::Completed, name: 'pro')),
        'refund' => app(RecordRefundAction::class)->execute(new RecordRefundData('stripe', 'pi_race')),
    };

    expect($recorded)->toBeInstanceOf(Model::class)
        ->and($recorded->exists)->toBeTrue()
        ->and($attempts)->toBe(2)
        ->and($model::query()->count())->toBe(1);
})->with(['purchase', 'subscription', 'refund']);
