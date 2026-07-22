# Identity map & unit of work (design note — the `attrecord-uow` companion package)

> **Status:** PLANNED — to be built as **`attrecord-uow`**, a companion package, with three small
> seams added to core (§9.1). Nothing implemented yet; this note is the design contract for that
> build. It is deliberately *capped*: §8 (Non-goals) is the fence, and §9 explains why the companion
> package — not core — is the vehicle.
> **Theme:** offer the two guarantees that make Data Mapper ORMs safe — **one row ↔ one object** and
> a **batched, correctly-ordered flush** — as an **opt-in mode** built on machinery attrecord already
> has, without importing Doctrine's weight or its hidden I/O.

## 1. Thesis

attrecord deliberately omits lazy loading, an identity map, and a unit of work (see the README
comparison). Dropping *lazy loading* is a pure win for its goals: no hidden reads, no accidental
N+1, and column access stays statically analysable. But dropping the other two costs real
guarantees:

- **No identity map** → you can load row #7 twice and hold two independent objects. Edit both, save
  both, and one silently clobbers the other. That is a genuine lost-update footgun.
- **No unit of work** → *you* own write ordering and batching. Nothing stops a per-record `save()`
  loop, and nothing orders inserts to satisfy foreign keys.

Both are recovered by an **opt-in session mode**, shipped as the `attrecord-uow` companion package
(§9). It costs a small, bounded amount of code because the expensive parts already exist, and it
*advances* attrecord's two pillars — legible performance, static analysability — rather than trading
them away.

## 2. Why it is cheap here

A unit of work is mostly a **coordinator**. attrecord already owns every hard piece:

| Piece a UoW needs | attrecord already has |
| --- | --- |
| Change detection | the per-record column **snapshot** (`isDirty()` / `dirtyFields()`) |
| Write executor | `RecordSet::insertAll()` / `upsertAll()` / `deleteAll()` — batched, deadlock-safe |
| FK dependency graph | `TableSchema::$foreignKeys` (already collected from attributes) |
| Deadlock-safe locking | `LockSet` + `LockTier` (tier-ordered, ascending-PK) |

So the genuinely *new* state is one registry (the identity map) plus one flag per record. The flush
itself is a topological sort dispatching to writers that already exist.

## 3. Core principle: flush is a pure function of record state

**There is no change-log.** The unit records no sequence of "you called persist, then you called
remove." `flush()` scans the identity map and buckets records by their own state:

| Record state | Operation | Batched via |
| --- | --- | --- |
| `_added` (flag) | INSERT | `insertAll()` |
| dirty (snapshot differs) | UPDATE | `upsertAll()` |
| `_removed` (flag) | DELETE | `deleteAll()` |

This is what keeps `save()` and `flush()` from ever disagreeing: **they read the same source of
truth.** `save()` is simply the eager, single-record form of what `flush()` does in a batch. A
record you `save()`d directly is clean and no longer new, so a later `flush()` skips it — no
double-write, no lost update, and no "managed change-set" bookkeeping to keep in sync.

**Only UPDATE is inferable.** Dirtiness *is* the intent to update, so it needs no marker. The other
two cannot be inferred and therefore need one each:

- **DELETE** — a clean record is not "dirty for deletion", so intent must be declared.
- **INSERT** — a freshly constructed record has no PK, so it is in no registry for `flush()` to find;
  something must put it in scope. This is the same problem Doctrine solves with `persist()`.

Both markers live **on the record**, not in a unit-side log (§5), so the "pure function of state"
property survives: `flush()` still reads only the records themselves. What is declined in §8 is the
*operation log*, not the *entry point* — those are two different things that `persist()` conflates.

## 4. Identity map

**Session-level and opt-in, all-or-nothing.** Activation is a session-level toggle alongside
`setConnection()` / `setTablePrefix()` — not a context object threaded through call stacks. When
enabled, *every* record hydrated from the database is deduped into a registry keyed by
`class + primary key`; a row already in the registry yields the **same object instance**. That is the
whole guarantee: one row ↔ one object, so divergent copies cannot exist and a lost update is
structurally impossible.

**Finders are map-authoritative.** In this mode, `getOne(7)` / `find(...)` that resolve an
already-mapped row return the **managed instance — including its unsaved edits** — rather than
re-`SELECT`ing and overwriting in-memory state. Refetching would silently discard the caller's
pending changes, which is exactly what the map exists to prevent. `refresh()` (§4.2) is the explicit
escape hatch.

**Relation loads route through the map too.** `load()` / `loadMissing()` must register (and dedupe)
the records they hydrate; otherwise the same `Tag` reached via two posts becomes two objects and the
guarantee is only half-true. Loading is centralised, so this is one integration point, not many.

**Membership rule.** A record enters the map when it is **hydrated from the database**, or when it
**acquires a PK through a write**. The second clause is what keeps PK-less write paths uniform:
`insertAll()`, `upsertAll()` and `upsertAllByUniqueKey()` all end with the record holding its PK, and
registration happens at that point. No path needs a special case — see §4.3 for how the unique-key
path resolves a PK *before* writing, and what happens when it lands on an already-managed row.

**Transient records are a second partition.** A record marked with `add()` (§5) has no PK yet, so it
cannot live in the PK-keyed registry. It sits in a **pending partition keyed by object identity**
until `flush()` inserts it; the write assigns its PK, and it then **migrates to the PK-keyed side**,
where the membership rule above applies unchanged. Nothing else in the map needs to know about the
distinction — it exists only because a PK-keyed structure cannot key something that has no PK.

### 4.1 Constructing a record with an already-managed PK

`SomeRecord::newWith(['pk' => $alreadyLoadedId])` is the case that can defeat the map: a
hand-constructed object never passed through it, so you would hold a second live object for a
managed row.

The rule mirrors insert semantics — **loud by default, opt into leniency** (the same shape as
`insertAll()` vs `upsertAll()`):

- **Default: throw.** Colliding with a managed row is the in-memory analogue of a duplicate-PK
  `INSERT`. The exception names the row and points at the fix.
- **Opt-in: return the existing instance.** The managed object is handed back **and the passed
  non-PK attributes are applied to it** (as `set()` would). Ignoring them would make passing them a
  silent no-op; applying them is the identity-map contract working as intended, and makes this a
  useful in-map upsert helper. Document plainly that it **mutates (and may dirty) the shared
  instance**, so a later `flush()` writes it.

This reads as a natural extension of the existing `firstOrNew` / `findOrCreate` / `getOneOrNew`
family — it is "get-or-new sourced from the in-memory map instead of the database."

**Scope of the rule:** it fires *only* when the mode is on **and** that PK is already mapped.
Outside the mode, or for a PK that has not been loaded, `newWith(['pk' => …])` stays unrestricted —
that is the legitimate "construct a detached record to upsert without loading it" idiom and must
keep working.

### 4.2 `refresh()`: clobber by default, opt-in reconcile

Because the map makes finders authoritative, `refresh()` is the only way to re-read a managed row.
The snapshot turns it into a **three-way merge for free**: `_snapshot` is the base, the current
properties are local, the fetched row is remote.

- **Default — clobber.** Overwrite every column from the database and reset the snapshot; the record
  comes back clean. This is the plain "re-read this row" contract, and it is the right default
  because it is the least surprising reading of the verb.
- **`reconcile: true` — keep local edits.** Per column: if it is **not** dirty (local == base), take
  the database value; if it **is** dirty, keep the local edit but **rebase the snapshot to the
  database value**, so the column stays dirty against the fresh base and still writes at flush.
- **Conflicts fall out of the same pass.** A column that is dirty locally *and* changed remotely is
  exactly the case where base, local and remote all differ. `reconcile` can report those columns
  (return them, or throw in a strict variant) instead of silently resolving last-writer-wins.

### 4.3 `upsertAllByUniqueKey()` and the map

Writing rows resolved by a *unique key* rather than by PK looks like it needs the map to be
searchable by unique key. **It does not** — the existing implementation already does the hard part:

```php
$this->resolveExistingPksByUniqueKey($schema, $conflictCols);   // batched tuple-IN SELECT
return $this->upsertAll();
```

`resolveExistingPksByUniqueKey()` resolves **unique-key tuple → PK in one batched query and assigns
the PK** onto each matching record. So by the time anything is written, every record corresponding to
an existing row already carries its PK — and the map is PK-keyed. **The match collapses to an O(1)
exact lookup**; no tuple index and no fuzzy matching are required.

The map-consult hooks in **between those two steps**, applied to *every* record then carrying a PK
(which covers both the ones resolution just assigned and any that arrived pre-keyed and skipped it):

| Map lookup on that PK | Action |
| --- | --- |
| Not present | Nothing — it registers after the write, per the §4 membership rule |
| Maps to **this same instance** | Nothing (the common case: loaded, then upserted as that object) |
| Maps to a **different instance `M`** | Write-collision → let the write proceed, then **propagate the written values onto `M` and rebase `M`'s snapshot for those columns**. The writer stays detached; **`M` remains the managed instance** for that PK |

Records that resolve to no existing row keep a null PK, are plainly INSERTed, receive their PK from
the database, and register per the membership rule — no collision is possible there.

**Why this merges where §4.1 throws.** The two rules differ because the *intent* differs. In §4.1 you
**constructed** a duplicate of a managed row — a mistake, so it is refused. Here you explicitly asked
to **write these values** in a legitimate bulk operation; failing it merely because someone happened
to load one of those rows earlier would be hostile and unpredictable. So the write proceeds and the
managed instance is brought up to date — which is the whole point: the map must not be left stale.

**Sub-case:** if `M` held *unsaved local edits* to a column the upsert wrote, propagating clobbers
them. That is defensible — the database now holds the upserted value, so `M`'s pending edit is
already invalid — but it is the same three-way shape as §4.2, so those columns should be **reported**
rather than silently resolved.

## 5. Declaring write intent: the `DefersWrites` trait

Per §3, only INSERT and DELETE need a declared intent; UPDATE is carried by dirtiness. Both markers
are sticky flags on the record, supplied by an opt-in trait:

```php
class Order extends Record { use DefersWrites; }

$rec->add();      // deferred: flags a transient record for INSERT at the next flush()
$rec->remove();   // deferred: flags a managed record for DELETE at the next flush()

$rec->save();     // immediate: INSERT/UPDATE now (existing behaviour, mode-independent)
$rec->delete();   // immediate: DELETE now  (existing behaviour, mode-independent)
```

The pairing mirrors the write side: **`add()`/`remove()` : `save()`/`delete()` :: `flush()` :
immediate** — deferred form vs eager form. Keeping the flags **on the record** (rather than
`$unit->persist()` / `$unit->remove()`) is what lets §3 hold: flush stays a pure function of record
state, with no unit-side registry. It is also why the trait is named for *deferral* rather than for
CRUD — update is deliberately absent, because dirtiness already is its marker.

Records that are only ever loaded-and-updated need no trait at all.

Rules:

1. **`_removed` beats dirty.** A record both mutated and removed is deleted, not updated. The flags
   are sticky; mutating after `remove()` is moot.
2. **Added-and-removed is a drop.** `remove()` on a record that was only `add()`ed (never persisted)
   emits no SQL — flush simply discards it from the pending partition.
3. **Post-flush reset.** Deleted records are **unmapped** and reset (`_isNew` again, flags cleared),
   mirroring what immediate `delete()` already does. Inserted records clear `_added`, receive their
   PK and migrate into the PK-keyed map (§4). Otherwise the map holds ghosts.
4. **`delete()` must unmap too** when the mode is on, so a later finder cannot hand back a
   just-deleted instance.
5. **Both are managed-mode primitives.** Called with no flush path active they would be *silent
   no-ops* — a flagged write that never runs. They must throw (or at minimum warn) outside the mode;
   `save()` / `delete()` remain the mode-independent immediate forms.

## 6. Flush: ordering and locking

A flush has **two orthogonal orderings**. They do not compete — they govern different phases:

1. **Lock acquisition order — delegated to `LockSet`.** Every managed record that will be UPDATEd or
   DELETEd has its lock acquired **up front**, in the canonical tier-ordered, ascending-PK order.
   New (PK-null) rows need no lock. Acquiring all locks up front in a global order *is* the
   deadlock-safe pattern, so the unit is a **better** home for it than ad-hoc per-operation locking:
   it centralises the discipline instead of scattering it.
2. **Statement order — FK topological sort.** Derived from `TableSchema::$foreignKeys`: parents
   before children for INSERT, the reverse for DELETE. Within each class + operation, records are
   batched into a single `insertAll` / `upsertAll` / `deleteAll`.

So: **acquire (LockSet, tier+PK) → emit (FK-topo, batched)**, all inside one transaction.

This is where the mode pays for itself on the performance pillar: the correct thing (one batched,
FK-ordered, lock-safe write) becomes the *easy* thing, instead of something each caller
hand-orchestrates — or gets wrong with a `save()` loop.

## 7. Lifetime, staleness, and optimistic locking

An identity map does not *create* staleness — it makes it **persistent**. Without a map, every load
re-reads, so you get freshness by accident (at the price of divergent copies). With one, you hold the
first-loaded state until you clear or `refresh()`. The consequence is a hard rule:

> **The map's lifetime must be bounded by the unit of work, not by the process.**

This is exactly how Doctrine handles it, and the precedent is worth following:

- **`clear()` between units.** Doctrine's documented batch-processing idiom is to clear the
  EntityManager every N items / between jobs, detaching everything and emptying the identity map;
  long-running Symfony Messenger workers clear between messages. Their EntityManager is meant to be
  short-lived even when the *process* is not. Since this design is session-level with no unit object
  (§4), attrecord's equivalent — an explicit **`clear()`, called between jobs** — is a documented
  *obligation* for long-running processes, not a nicety.
- **A version column for concurrent writes.** Doctrine's real answer to another process changing the
  row underneath you is **optimistic locking** via a `@Version` column: the write carries
  `AND version = ?`, bumps it, and raises on a zero-row match instead of silently clobbering.

That second point identified a genuine prerequisite, and it is why **optimistic locking was built
first**: attrecord had **pessimistic** locking (`LockSet` / `FOR UPDATE`), advisory locks and
transient-retry, but nothing to detect a concurrent write. Without that, a stale managed record
flushes last-writer-wins.

> **A `#[Version]` column is a prerequisite for running the map in a long-lived process**, not a
> separate nice-to-have.

`#[Version]` lands in **0.8.0** (single-record `save()` guarded; see the CHANGELOG for the bulk-path
scope), so the companion can rely on it. For request-scoped use — the common case — the map dies with
the request and pessimistic locking already covers read-then-write critical sections, so the version
column only becomes load-bearing for workers and for cross-request read/write.

## 8. Non-goals (the fence)

This section is the point of the note. The design is cheap **only** while it stays here; every item
below is where the weight lives in Doctrine, and each one is declined on purpose.

- **No auto-flush. Ever.** `flush()` is always an explicit call — never on destruct, never before a
  query, never on scope exit. Deferred *batching* is fine; deferred *hidden* I/O is precisely what
  attrecord refuses. This is the single most important line in the design.
- **No lazy loading, no proxies.** Unchanged: accessing an unloaded relation still never queries.
  The identity map is about *deduping what you loaded*, not about *loading on access*.
- **No cascade-persist / cascade-remove**, no orphan removal. Reachability does not imply
  persistence; you flag what you mean.
- **No detach / reattach / merge graph semantics.** The managed-state model is exactly: *in the map
  or not*, plus the collision check of §4.1 and the `_removed` flag of §5. No four-state entity
  lifecycle.
- **No nested or independent units.** The unit *is* the session. Simpler to reason about and needs
  no `$unit` threaded through call stacks; the cost is that `flush()` is all-or-nothing across the
  session, which suits request-scoped PHP. Long-running workers get an explicit `clear()` (§7).
- **No change-log — but yes, an entry point.** `persist()` in Doctrine conflates two things: an
  *operation log* and the *entry into managed state*. Only the first is declined. `add()` (§5) is a
  sticky flag on the record, not a recorded sequence of calls, so `flush()` remains a pure function
  of record state (§3). What is refused is a unit-side list of "things you did."
- **No secondary unique-key index in the map.** The tempting design — index managed records by each
  declared unique key so unique-key writes can match tuples directly — is declined: it costs index
  maintenance on every hydration and, worse, carries a **mutable-key invalidation problem** (change a
  unique-key column in memory and the entry silently goes stale). Per §4.3 the PK is already resolved
  upstream, so the index buys nothing. The map stays keyed by PK, and only by PK.
- **No magic accessors.** Everything here is plain typed methods, so static analysis is untouched.

If a requested feature needs one of the above, that is the signal it belongs in Doctrine, not here.

## 9. Why it ships as a companion package

This is built as **`attrecord-uow`**, a separate opt-in package, rather than inside core. That is a
deliberate packaging decision, and it follows from what the feature actually costs.

By the usual metrics the design is cheap — opt-in, prunable, no dependencies, a registry class and a
couple of flags, reusing the existing writers. Those are the wrong metrics. The two real costs are:

- **Test and maintenance combinatorics.** A mapped-vs-unmapped mode means every finder and every
  write path has *two* correct behaviours. The **code** is prunable; the **test matrix is not**.
  This, not line count, is where the weight lands.
- **Conceptual surface.** The pitch is "small, dependency-free Active Record." Identity map, flush,
  managed state and collision rules are Data-Mapper vocabulary. Users who never enable the mode would
  still meet it in the docs and in `Record`'s state. Identity blur is paid in the README, not the
  profiler.

**In core, both of those are permanent. Outside it, both are bounded** — which is the entire reason
for the split. With seams, core's variability is confined to *two hook points* (installed or not), so
**core stays single-mode**: the mapped-versus-unmapped combinatorics live in the companion's own
suite instead of leaking into every finder and write path, and core's README keeps its Active-Record
pitch intact.

The general rule this instantiates, worth applying to anything of similar shape: **"does it add a
second mode that every existing path must be correct in?"** If yes, it wants its own package.

### 9.1 The seams core must expose

Core gains three extension points — nothing more. They ship in core; everything else lives in the
companion.

**The seam is small.** The row → *new* instance pattern exists at only three sites, all inside

**The seam is small.** The row → *new* instance pattern exists at only three sites, all inside
`Record.php` — `getOne()`, `find()`, and `hydrateFromArray()`. Every other hydration
(`LockSet`, `RecordSet::readBackAll()`, `save()`'s read-back) fills an **already-existing** instance
and needs no hook. Relation loaders do not instantiate directly — they route through the target
class's finders — so covering `getOne()`/`find()` covers relations for free.

**Seam 1 — row → instance choke point** (the only real refactor: consolidate those three sites, which
is worthwhile hygiene on its own):

```php
// all finders funnel through this
protected static function hydrateNew(array $row): static;
public static function setRecordFactory(?RecordFactory $factory): void;

interface RecordFactory {
    public function instantiate(string $class, array $row): Record;
}
```

The companion's factory checks its map for that PK and either **returns the managed instance**
(ignoring the row, per §4's map-authoritative rule) or hydrates and registers a new one.

**Seam 2 — post-write observer**, so the map registers records that acquire a PK through a *direct*
`save()` (not only via the companion's `flush()`), and can run the §4.3 collision consult:

```php
public static function setWriteObserver(?WriteObserver $observer): void;

interface WriteObserver {
    public function written(array $records, WriteOp $op): void;
}
```

**Seam 3 — snapshot accessor.** The §4.2 three-way reconcile needs the *base*; `toRawArray()` returns
**current** values, not the stored snapshot. One small `@internal` reader: `snapshot(): array`.

**What needs no seam at all.** The entire §3 change detection and §6 flush are reachable from today's
public surface: `isNew()`, `isDirty()`, `dirtyFields()`, `markClean()`, `hydrateFromRow()`,
`patchColumnsFromRow()`, `insertAll()` / `upsertAll()` / `deleteAll()`, `TableSchema::$foreignKeys`,
and `LockSet`. The §5 markers also need no core change — the companion ships the **`DefersWrites`
trait** (the `_added` / `_removed` flags plus `add()` / `remove()` and their predicates), and flush
checks `instanceof DefersWrites`.

**One simplification the split buys.** Drop the `newWith()`-time collision check of §4.1 and catch
collisions at write time (§4.3) instead. Public typed properties are non-interceptable — `$r->id = 7`
cannot be hooked, which is the no-magic pillar biting — so the write choke point is the only
universally reliable moment anyway. One fewer seam, marginally later detection, and no gap that
matters, since the damage only occurs on write.

**Honest costs of the split.** Two global extension points become permanent public contract (static
and mutable, like `setConnection()` / `setTablePrefix()`, so stylistically in character — but no
longer casually changeable). And the companion leans on `@internal` methods across a package
boundary (`hydrateFromRow`, `patchColumnsFromRow`, `markClean`, `snapshot`), which is a coupling
smell: it needs a tight version constraint and a note that those are internal-but-contractual for the
companion.

Note that these costs land on **core** and are paid the moment the seams ship — before a single line
of the companion exists. That sets the sequencing: the three seams are a core release in their own
right, and they should be designed as if the companion may never arrive, because once published they
are contract regardless.

## 10. Why this does not compromise the pillars

- **Static analysis:** the whole surface is ordinary typed methods (`flush()`, `remove()`,
  `refresh()`, the finders). No `__get`/`__set`, no proxy subclasses, no `@property` docblocks.
  Column access remains real typed properties.
- **Legible I/O:** reads are still explicit (no lazy loading), and writes stay explicit because
  `flush()` is never automatic. What changes is that writes become *batched and correctly ordered*
  by default — the performance-shaped outcome attrecord already pushes people toward with its bulk
  API and its "never loop DB calls" guidance.
- **Prunable:** the mode is opt-in and self-contained. Deployments that never enable it carry the
  registry class and a flag, nothing more — consistent with the "opt-in, prunable composition"
  stance taken for the concurrency machinery in `arch-concurrency.md`.
