<?php

declare(strict_types=1);

namespace Nandan108\Attrecord\Enum;

/** @api */
enum RelationType
{
    /**
     * This record is the "one" side; the related table has a FK pointing here.
     * e.g. PurchaseOrder hasMany PurchaseOrderLine (po_id on lines table).
     */
    case OneToMany;

    /**
     * This record holds the FK pointing to the related table.
     * e.g. PurchaseOrderLine belongsTo PurchaseOrder (po_id on this table).
     */
    case ManyToOne;

    /**
     * This record holds the FK; there is exactly one related record.
     */
    case OneToOne;

    /**
     * The related table holds the FK pointing here; there is exactly one related record.
     */
    case OneToOneReversed;

    /**
     * Polymorphic parent side — the related table has a type-discriminator column and a
     * FK column pointing here. Returns a RecordSet of related records.
     *
     * Required attribute parameters: class, morphType, morphKey, morphValue.
     *
     * e.g. Order morphMany Tag  (tags.tagable_type = 'order', tags.tagable_id = orders.id)
     */
    case MorphMany;

    /**
     * Polymorphic parent side — like MorphMany but returns a single related record or null.
     *
     * Required attribute parameters: class, morphType, morphKey, morphValue.
     */
    case MorphOne;

    /**
     * Polymorphic child side — this record holds a type-discriminator column and a FK column
     * that point to different parent tables depending on the discriminator value.
     *
     * Required attribute parameters: morphType, morphKey, morphMap.
     *
     * e.g. Tag morphTo (tagable_type + tagable_id → Order or Product)
     */
    case MorphTo;
}
