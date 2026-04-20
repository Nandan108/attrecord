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
}
