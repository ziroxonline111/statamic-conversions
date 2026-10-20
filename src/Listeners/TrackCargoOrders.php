<?php

namespace KareliaLabs\Conversions\Listeners;

use Illuminate\Support\Facades\Cache;
use KareliaLabs\Conversions\Conversion;
use KareliaLabs\Conversions\Conversions;
use KareliaLabs\Conversions\Settings;

/**
 * Purchases from Cargo (duncanmcclean/statamic-cargo).
 *
 * Payment is often confirmed by a webhook from the payment provider, so the
 * request that marks the order paid is not the customer's. The visitor's
 * details (IP, browser, click IDs, consent) are therefore saved when the order
 * is created during checkout, and used when the order is paid.
 */
class TrackCargoOrders
{
    public const CREATED = 'DuncanMcClean\\Cargo\\Events\\OrderCreated';

    public const PAID = 'DuncanMcClean\\Cargo\\Events\\OrderPaid';

    public function created(object $event): void
    {
        $order = $event->order ?? null;

        if (! $order || ! Settings::trackOrders() || $this->looksLikeWebhook()) {
            return;
        }

        $context = Conversion::make('purchase')->fromRequest(request());

        Cache::put($this->key($order), [
            'url' => $context->url,
            'referrer' => $context->referrer,
            'ip' => $context->ip,
            'user_agent' => $context->userAgent,
            'cookies' => $context->cookies,
            'attribution' => $context->attribution,
            'consent' => $context->consent,
        ], now()->addDays(14));
    }

    public function paid(object $event): void
    {
        $order = $event->order ?? null;

        if (! $order || ! Settings::trackOrders()) {
            return;
        }

        $conversion = Conversion::make('purchase');
        $context = Cache::pull($this->key($order));

        if ($context) {
            foreach (['url' => 'url', 'referrer' => 'referrer', 'ip' => 'ip', 'user_agent' => 'userAgent', 'cookies' => 'cookies', 'attribution' => 'attribution', 'consent' => 'consent'] as $key => $property) {
                $conversion->{$property} = $context[$key] ?? $conversion->{$property};
            }
        } elseif (! $this->looksLikeWebhook()) {
            $conversion->fromRequest(request());
        } else {
            // Nothing is known about the visitor, so consent cannot be assumed.
            $conversion->withConsent(\KareliaLabs\Conversions\Settings::consentMode() === 'always');
        }

        $customer = $this->call($order, 'customer');
        $email = is_object($customer) ? $this->call($customer, 'email') : null;
        $name = is_object($customer) ? (string) $this->call($customer, 'name') : '';

        $conversion
            ->user(array_filter([
                'email' => $email ?? $this->call($order, 'get', 'customer_email'),
                'first_name' => $name !== '' ? strtok($name, ' ') : null,
                'phone' => $this->call($order, 'get', 'phone'),
                'external_id' => is_object($customer) ? (string) $this->call($customer, 'id') : null,
            ]))
            ->value($this->money($this->call($order, 'grandTotal')), $this->currency($order))
            ->orderId((string) ($this->call($order, 'orderNumber') ?? $this->call($order, 'id')))
            ->items($this->items($order))
            ->source('order', 'cargo', (string) $this->call($order, 'id'), __('Order #:number', ['number' => $this->call($order, 'orderNumber') ?? $this->call($order, 'id')]));

        app(Conversions::class)->track($conversion);
    }

    private function items(object $order): array
    {
        $lineItems = $this->call($order, 'lineItems');

        if (! is_iterable($lineItems)) {
            return [];
        }

        $items = [];

        foreach ($lineItems as $line) {
            $product = $this->call($line, 'product');
            $quantity = (int) ($this->call($line, 'quantity') ?? 1);
            $unit = $this->call($line, 'unitPrice');
            $total = $this->call($line, 'total');

            $items[] = array_filter([
                'id' => (string) (is_object($product) ? $this->call($product, 'id') : $this->call($line, 'id')),
                'name' => is_object($product) ? (string) ($this->call($product, 'get', 'title') ?? '') : null,
                'quantity' => $quantity,
                'price' => $unit !== null ? $this->money($unit) : ($total !== null && $quantity > 0 ? round($this->money($total) / $quantity, 2) : null),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $items;
    }

    /** Cargo stores amounts in the smallest unit (cents). */
    private function money(mixed $amount): ?float
    {
        return is_numeric($amount) ? round(((int) $amount) / 100, 2) : null;
    }

    private function currency(object $order): ?string
    {
        $site = $this->call($order, 'site');
        $currency = is_object($site) ? $this->call($site, 'attribute', 'currency') : null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : null;
    }

    private function key(object $order): string
    {
        return 'karelia-conversions:cargo:'.$this->call($order, 'id');
    }

    private function looksLikeWebhook(): bool
    {
        $request = request();

        return ! $request || str_contains($request->path(), 'webhook') || ! $request->hasHeader('user-agent');
    }

    private function call(object $target, string $method, mixed ...$args): mixed
    {
        try {
            return method_exists($target, $method) || method_exists($target, '__call') ? $target->{$method}(...$args) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
