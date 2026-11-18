<?php

declare(strict_types=1);

namespace ShopBite\Order;

/**
 * The entities that belong to one order, with the path to the order's sales channel (for read
 * filters) and the foreign key to their parent (for resolving the sales channel of a write).
 */
final class OrderFamily
{
    /** Entity name => path of the order's sales channel id. */
    public const array SALES_CHANNEL_PATHS = [
        'order' => 'salesChannelId',
        'order_line_item' => 'order.salesChannelId',
        'order_delivery' => 'order.salesChannelId',
        'order_transaction' => 'order.salesChannelId',
        'order_customer' => 'order.salesChannelId',
        'order_address' => 'order.salesChannelId',
        'order_tag' => 'order.salesChannelId',
        'document' => 'order.salesChannelId',
        'order_delivery_position' => 'orderDelivery.order.salesChannelId',
        'order_line_item_download' => 'orderLineItem.order.salesChannelId',
        'order_transaction_capture' => 'transaction.order.salesChannelId',
        'order_transaction_capture_refund' => 'transactionCapture.transaction.order.salesChannelId',
        'order_transaction_capture_refund_position' => 'orderLineItem.order.salesChannelId',
    ];

    /** Entity name => [foreign key column, parent entity]; `order` is the root. */
    public const array PARENTS = [
        'order_line_item' => ['order_id', 'order'],
        'order_delivery' => ['order_id', 'order'],
        'order_transaction' => ['order_id', 'order'],
        'order_customer' => ['order_id', 'order'],
        'order_address' => ['order_id', 'order'],
        'order_tag' => ['order_id', 'order'],
        'document' => ['order_id', 'order'],
        'order_delivery_position' => ['order_delivery_id', 'order_delivery'],
        'order_line_item_download' => ['order_line_item_id', 'order_line_item'],
        'order_transaction_capture' => ['order_transaction_id', 'order_transaction'],
        'order_transaction_capture_refund' => ['capture_id', 'order_transaction_capture'],
        'order_transaction_capture_refund_position' => ['refund_id', 'order_transaction_capture_refund'],
    ];

    public static function contains(string $entityName): bool
    {
        return \array_key_exists($entityName, self::SALES_CHANNEL_PATHS);
    }
}
