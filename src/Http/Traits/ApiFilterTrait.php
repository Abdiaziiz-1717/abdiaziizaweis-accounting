<?php

namespace Abdiaziizaweis\Accounting\Http\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ApiFilterTrait
{
    /**
     * Apply common API filtering (search, sorting, pagination) to query builder
     *
     * @param Builder $query
     * @param Request $request
     * @param array $searchableFields Fields that can be searched
     * @param array $sortableFields Fields that can be sorted
     * @param string $defaultSortField Default field to sort by if none specified
     * @param string $defaultSortDirection Default sort direction
     * @return Builder
     */
    protected function applyApiFilters(
        Builder $query,
        Request $request,
        array $searchableFields = [],
        array $sortableFields = ['id', 'created_at', 'updated_at'],
        string $defaultSortField = 'created_at',
        string $defaultSortDirection = 'desc'
    ): Builder {
        $this->applyDynamicCriteria($query, $request);
        $this->applySearch($query, $request, $searchableFields);
        $this->applySorting($query, $request, $sortableFields, $defaultSortField, $defaultSortDirection);

        return $query;
    }

    protected function applyDynamicCriteria(Builder $query, Request $request): Builder
    {
        if ($request->has('filter') && is_array($request->query('filter'))) {
            $criteria = $request->query('filter');

            foreach ($criteria as $field => $condition) {
                if (is_array($condition)) {
                    foreach ($condition as $operator => $value) {
                        $this->applyOperatorCondition($query, $field, $operator, $value);
                    }
                } else {
                    if (strpos($condition, ',') !== false) {
                        $values = explode(',', $condition);
                        $query->whereIn($field, $values);
                    } else {
                        $query->where($field, $condition);
                    }
                }
            }
        }

        return $query;
    }

    private function applyOperatorCondition(Builder $query, string $field, string $operator, $value): void
    {
        switch ($operator) {
            case 'gt':
                $query->where($field, '>', $value);
                break;
            case 'gte':
                $query->where($field, '>=', $value);
                break;
            case 'lt':
                $query->where($field, '<', $value);
                break;
            case 'lte':
                $query->where($field, '<=', $value);
                break;
            case 'not':
                $query->where($field, '!=', $value);
                break;
            case 'like':
                $query->where($field, 'like', "%$value%");
                break;
            case 'in':
                $values = is_array($value) ? $value : explode(',', $value);
                $query->whereIn($field, $values);
                break;
            case 'not_in':
                $values = is_array($value) ? $value : explode(',', $value);
                $query->whereNotIn($field, $values);
                break;
            case 'between':
                $values = is_array($value) ? $value : explode(',', $value);
                if (count($values) === 2) {
                    $query->whereBetween($field, $values);
                }
                break;
            case 'null':
                if ($value === true || $value === 'true' || $value === 1) {
                    $query->whereNull($field);
                } else {
                    $query->whereNotNull($field);
                }
                break;
            case 'starts':
                $query->where($field, 'like', "$value%");
                break;
            case 'ends':
                $query->where($field, 'like', "%$value");
                break;
            case 'contains':
                $query->where($field, 'like', "%$value%");
            default:
                $query->where($field, $value);
                break;
        }
    }

    protected function applySearch(Builder $query, Request $request, array $searchableFields): Builder
    {
        if ($request->has('search_term') && $request->has('search_fields') && ! empty($searchableFields)) {
            $searchTerm = $request->input('search_term');

            $searchFields = is_array($request->input('search_fields'))
                ? $request->input('search_fields')
                : explode(',', $request->input('search_fields'));

            $searchFields = array_filter($searchFields, function ($field) use ($searchableFields) {
                return in_array(trim($field), $searchableFields);
            });

            if (! empty($searchFields)) {
                $query->where(function ($q) use ($searchFields, $searchTerm) {
                    foreach ($searchFields as $field) {
                        $q->orWhere($field, 'like', '%'.$searchTerm.'%');
                    }
                });
            }
        }

        if ($request->has('q') && ! empty($searchableFields)) {
            $searchTerm = $request->q;

            $query->where(function ($q) use ($searchableFields, $searchTerm) {
                foreach ($searchableFields as $field) {
                    $q->orWhere($field, 'like', '%'.$searchTerm.'%');
                }
            });
        }

        return $query;
    }

    protected function applySorting(
        Builder $query,
        Request $request,
        array $sortableFields,
        string $defaultSortField,
        string $defaultSortDirection
    ): Builder {
        if ($request->has('sort_by')) {
            $sortField = $request->input('sort_by');

            if (! in_array($sortField, $sortableFields)) {
                $sortField = $defaultSortField;
            }

            $sortDirection = $request->input('sort_direction', $defaultSortDirection);
            if (! in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = $defaultSortDirection;
            }

            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy($defaultSortField, $defaultSortDirection);
        }

        return $query;
    }

    protected function paginateResponse(
        Builder $query,
        Request $request,
        int $defaultPerPage = 15,
        int $maxPerPage = 100
    ) {
        if ($request->boolean('for_pos')) {
            $defaultPerPage = min($defaultPerPage, 24);
            $maxPerPage = min($maxPerPage, 48);
        }

        $perPage = (int) $request->input('per_page', $defaultPerPage);
        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }
        $perPage = min($perPage, $maxPerPage);

        if (
            ! $request->boolean('for_pos')
            && $request->has('all')
            && (
                $request->input('all') === 'true'
                || $request->boolean('all')
            )
        ) {
            $perPage = min(1000, max($maxPerPage, 1000));
        }

        return response()->json($query->paginate($perPage));
    }

    protected function applyRelevanceSorting(
        Builder $query,
        string $searchTerm,
        string $primaryField = 'title',
        array $allowedFields = []
    ): Builder {
        if (! empty($allowedFields) && ! in_array($primaryField, $allowedFields)) {
            $primaryField = 'id';
        }

        $primaryField = $this->validateFieldExists($query, $primaryField);

        return $query->orderByRaw('CASE
            WHEN '.$this->escapeFieldName($primaryField).' = ? THEN 1
            WHEN '.$this->escapeFieldName($primaryField).' LIKE ? THEN 2
            WHEN '.$this->escapeFieldName($primaryField).' LIKE ? THEN 3
            ELSE 4
        END', [
            $searchTerm,
            $searchTerm.'%',
            '%'.$searchTerm.'%',
        ]);
    }

    private function validateFieldExists(Builder $query, string $fieldName): string
    {
        try {
            $model = $query->getModel();
            $table = $model->getTable();
            $connection = $model->getConnection();
            $columns = $connection->getSchemaBuilder()->getColumnListing($table);

            if (in_array($fieldName, $columns)) {
                return $fieldName;
            }

            return 'id';
        } catch (\Exception $e) {
            return 'id';
        }
    }

    private function escapeFieldName(string $fieldName): string
    {
        $cleaned = preg_replace('/[^a-zA-Z0-9_.]/', '', $fieldName);

        if (empty($cleaned) || strpos($cleaned, '..') !== false) {
            return 'id';
        }

        $suspiciousPatterns = [
            'union', 'select', 'insert', 'update', 'delete', 'drop', 'create', 'alter',
            'exec', 'execute', 'script', 'javascript', 'vbscript', 'onload', 'onerror',
        ];

        $lowerField = strtolower($cleaned);
        foreach ($suspiciousPatterns as $pattern) {
            if (strpos($lowerField, $pattern) !== false) {
                return 'id';
            }
        }

        $sqlKeywords = [
            'select', 'from', 'where', 'order', 'group', 'having', 'union', 'insert',
            'update', 'delete', 'drop', 'create', 'alter', 'table', 'database', 'index',
        ];

        if (in_array($lowerField, $sqlKeywords)) {
            return 'id';
        }

        return $cleaned;
    }
}