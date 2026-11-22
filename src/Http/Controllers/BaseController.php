<?php

namespace Jtech\Accounting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Jtech\Accounting\Contracts\AccountingAuthInterface;
use Jtech\Accounting\Contracts\AccountingContextInterface;
use Jtech\Accounting\Exceptions\UniqueRestoreBlockedException;
use Jtech\Accounting\Http\Traits\ApiFilterTrait;
use Jtech\Accounting\Http\Traits\ApiResponseTrait;

class BaseController extends Controller
{
    use ApiFilterTrait, ApiResponseTrait;

    protected $model;

    protected $searchableFields = [];

    protected $sortableFields = [];

    protected $defaultSortField = 'created_at';

    protected $defaultSortDirection = 'desc';

    protected $relationships = [];

    protected $validationRules = [];

    protected $ownershipColumn = 'owner_user_id';

    protected $ownershipEnabled = true;

    protected function hasOwnershipColumn(): bool
    {
        if (! $this->model || ! $this->ownershipEnabled) {
            return false;
        }

        $table = (new $this->model)->getTable();

        return Schema::hasColumn($table, $this->ownershipColumn);
    }

    protected function shouldRestrictToOwnData(): bool
    {
        $user = app(AccountingAuthInterface::class)->user();

        if (! $user || ! $this->hasOwnershipColumn()) {
            return false;
        }

        return (bool) ($user->own_data_only ?? false);
    }

    protected function applyOwnershipScope($query)
    {
        $user = app(AccountingAuthInterface::class)->user();

        if ($this->shouldRestrictToOwnData() && $user) {
            $query->where($this->ownershipColumn, $user->id);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->applyOwnershipScope($this->model::query());

        $query = $this->applyApiFilters(
            $query,
            $request,
            $this->searchableFields,
            $this->sortableFields,
            $this->defaultSortField,
            $this->defaultSortDirection
        );

        if (! empty($this->relationships)) {
            $query->with($this->relationships);
        }

        return $this->paginateResponse($query, $request);
    }

    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|string|in:'.implode(',', $this->sortableFields),
            'sort_direction' => 'nullable|string|in:asc,desc',
        ]);

        $query = $this->applyOwnershipScope($this->model::query());

        if (! empty($this->relationships)) {
            $query->with($this->relationships);
        }

        $query = $this->applySearch($query, $request, $this->searchableFields);

        if ($request->has('sort_by')) {
            $query = $this->applySorting(
                $query,
                $request,
                $this->sortableFields,
                $this->defaultSortField,
                $this->defaultSortDirection
            );
        } else {
            $primaryField = ! empty($this->searchableFields) ? $this->searchableFields[0] : 'id';
            $query = $this->applyRelevanceSorting($query, $request->q, $primaryField);
        }

        return $this->paginateResponse($query, $request);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules['store'] ?? []);

        if ($this->hasOwnershipColumn()) {
            $validated[$this->ownershipColumn] = app(AccountingAuthInterface::class)->user()?->id;
        }

        $model = $this->model::create($validated);
        $this->logActivity(
            class_basename($this->model).' was created',
            $model,
            ['attributes' => $model->getAttributes()],
            'created'
        );

        return $this->createdResponse($model);
    }

    public function show($id)
    {
        $model = $this->applyOwnershipScope($this->model::with($this->relationships))->find($id);
        if (! $model) {
            return $this->notFoundResponse();
        }

        return $this->successResponse($model);
    }

    public function update(Request $request, $id)
    {
        $model = $this->applyOwnershipScope($this->model::query())->find($id);
        if (! $model) {
            return $this->notFoundResponse();
        }
        $validated = $request->validate($this->validationRules['update'] ?? []);
        $old = $model->getOriginal();
        $model->update($validated);
        $this->logActivity(
            class_basename($this->model).' was updated',
            $model,
            ['old' => $old, 'attributes' => $model->getAttributes()],
            'updated'
        );

        return $this->successResponse($model, 'Resource updated successfully');
    }

    public function destroy($id)
    {
        $model = $this->applyOwnershipScope($this->model::query())->find($id);
        if (! $model) {
            return $this->notFoundResponse();
        }
        $model->delete();
        $this->logActivity(
            class_basename($this->model).' was deleted',
            $model,
            ['old' => $model->getOriginal()],
            'deleted'
        );

        return $this->noContentResponse('Resource deleted successfully');
    }

    public function trashed(Request $request)
    {
        $query = $this->applyOwnershipScope($this->model::onlyTrashed());

        if ($request->has('sort_by')) {
            $sortField = $request->input('sort_by', 'id');
            $sortDirection = $request->input('sort_direction', 'asc');
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('deleted_at', 'desc');
        }

        return $this->paginateResponse($query, $request);
    }

    public function restore($id)
    {
        $model = $this->applyOwnershipScope($this->model::onlyTrashed())->find($id);
        if (! $model) {
            return $this->notFoundResponse();
        }

        try {
            $model->restore();
        } catch (UniqueRestoreBlockedException $e) {
            return $this->errorResponse($e->getMessage(), [
                'column' => $e->column,
                'value' => $e->originalValue,
            ], 422);
        }

        $this->logActivity(
            class_basename($this->model).' was restored',
            $model,
            ['attributes' => $model->getAttributes()],
            'restored'
        );

        return $this->successResponse($model, 'Resource restored successfully');
    }

    public function forceDestroy($id)
    {
        $model = $this->applyOwnershipScope($this->model::withTrashed())->find($id);
        if (! $model) {
            return $this->notFoundResponse();
        }

        $key = $model->getKey();
        $attributes = $model->getAttributes();

        try {
            $model->exists = true;
            $deleted = $model->forceDelete();
        } catch (\Throwable $e) {
            return $this->errorResponse(
                $e->getMessage() ?: 'Failed to permanently delete resource.',
                [],
                422
            );
        }

        if ($deleted === false) {
            return $this->errorResponse('Failed to permanently delete resource.', [], 422);
        }

        if ($this->model::withTrashed()->whereKey($key)->exists()) {
            $removed = $model->newQueryWithoutScopes()
                ->whereKey($key)
                ->toBase()
                ->delete();

            if ($removed === 0) {
                return $this->errorResponse('Failed to permanently delete resource.', [], 422);
            }
        }

        $this->logActivity(
            class_basename($this->model).' was permanently deleted',
            null,
            ['attributes' => $attributes, 'id' => $key],
            'force_deleted'
        );

        return $this->successResponse(null, 'Resource permanently deleted');
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
        ]);
        $scopedQuery = $this->applyOwnershipScope($this->model::query())->whereIn('id', $validated['ids']);
        $models = $scopedQuery->get();
        $count = (clone $scopedQuery)->delete();
        $subject = $models->first() ?: new $this->model;
        $this->logActivity(
            class_basename($this->model).' records were bulk deleted',
            $subject,
            ['ids' => $validated['ids'], 'count' => $count],
            'bulk_deleted'
        );

        return $this->successResponse([
            'deleted_count' => $count,
        ], $count.' records have been deleted');
    }

    public function bulkRestore(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
        ]);
        $scopedQuery = $this->applyOwnershipScope($this->model::onlyTrashed())->whereIn('id', $validated['ids']);
        $models = $scopedQuery->get();
        $restored = 0;
        $blocked = [];

        foreach ($models as $model) {
            try {
                $model->restore();
                $restored++;
            } catch (UniqueRestoreBlockedException $e) {
                $blocked[] = [
                    'id' => $model->getKey(),
                    'message' => $e->getMessage(),
                    'column' => $e->column,
                    'value' => $e->originalValue,
                ];
            }
        }

        if ($restored === 0 && $blocked !== []) {
            return $this->errorResponse(
                'Could not restore any records because unique values are already in use.',
                ['blocked' => $blocked],
                422
            );
        }

        $subject = $models->first() ?: new $this->model;
        $this->logActivity(
            class_basename($this->model).' records were bulk restored',
            $subject,
            ['ids' => $validated['ids'], 'count' => $restored, 'blocked' => $blocked],
            'bulk_restored'
        );

        $message = $restored.' records have been restored';
        if ($blocked !== []) {
            $message .= '; '.count($blocked).' blocked due to unique conflicts';
        }

        return $this->successResponse([
            'restored_count' => $restored,
            'blocked' => $blocked,
        ], $message);
    }

    public function bulkForceDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer',
        ]);
        $scopedQuery = $this->applyOwnershipScope($this->model::withTrashed())->whereIn('id', $validated['ids']);
        $models = $scopedQuery->get();
        $count = 0;
        $failed = [];

        foreach ($models as $model) {
            $key = $model->getKey();
            try {
                $model->exists = true;
                $model->forceDelete();
            } catch (\Throwable $e) {
                $failed[] = ['id' => $key, 'message' => $e->getMessage()];
                continue;
            }

            if ($this->model::withTrashed()->whereKey($key)->exists()) {
                $removed = $model->newQueryWithoutScopes()
                    ->whereKey($key)
                    ->toBase()
                    ->delete();
                if ($removed === 0) {
                    $failed[] = ['id' => $key, 'message' => 'Row still present after force delete.'];
                    continue;
                }
            }

            $count++;
        }

        if ($count === 0 && $failed !== []) {
            return $this->errorResponse(
                'Could not permanently delete any of the selected records.',
                ['failed' => $failed],
                422
            );
        }

        $this->logActivity(
            class_basename($this->model).' records were bulk permanently deleted',
            null,
            ['ids' => $validated['ids'], 'count' => $count, 'failed' => $failed],
            'bulk_force_deleted'
        );

        $message = $count.' records have been permanently deleted';
        if ($failed !== []) {
            $message .= '; '.count($failed).' failed';
        }

        return response()->json([
            'message' => $message,
            'deleted_count' => $count,
            'failed' => $failed,
        ]);
    }

    protected function logActivity(string $description, $subject = null, array $properties = [], ?string $event = null): void
    {
        if (! function_exists('activity')) {
            return;
        }

        $actor = app(AccountingAuthInterface::class)->user();
        if ($actor && ($actor->master_user_id ?? null)) {
            $properties['master_user_id'] = (int) $actor->master_user_id;
            $properties['is_master_support'] = true;
            $properties['support_user_id'] = (int) $actor->id;
        }

        $logName = $subject ? strtolower(class_basename($subject)) : strtolower(class_basename($this->model));
        $logger = activity($logName)
            ->causedBy($actor)
            ->withProperties($properties);
        if ($subject) {
            $logger->performedOn($subject);
        }
        if ($event) {
            $logger->event($event);
        }
        $activity = $logger->log($description);

        $context = app(AccountingContextInterface::class);
        $branchId = $context->getBranchId();
        $orgId = $context->getOrganizationId();
        if ($activity && ($branchId || $orgId) && $activity instanceof \Spatie\Activitylog\Models\Activity) {
            if ($branchId) {
                $activity->branch_id = $branchId;
            }
            if ($orgId) {
                $activity->organization_id = $orgId;
            }
            $activity->save();
        }
    }
}