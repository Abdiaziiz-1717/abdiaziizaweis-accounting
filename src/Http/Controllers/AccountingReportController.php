<?php

namespace Abdiaziizaweis\Accounting\Http\Controllers;

use Illuminate\Routing\Controller;
use Abdiaziizaweis\Accounting\Services\AccountingReportService;
use Abdiaziizaweis\Accounting\Http\Traits\ApiResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingReportController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private readonly AccountingReportService $reports,
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);

        return $this->successResponse($this->reports->trialBalance($asOf));
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return $this->successResponse($this->reports->profitAndLoss($from, $to));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $fyStart = $request->filled('fy_start')
            ? Carbon::parse($request->input('fy_start'))->startOfDay()
            : $asOf->copy()->startOfYear();

        return $this->successResponse($this->reports->balanceSheet($asOf, $fyStart));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return $this->successResponse($this->reports->cashFlow($from, $to));
    }

    public function accountStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        [$from, $to] = $this->period($request);

        try {
            return $this->successResponse(
                $this->reports->accountStatement((int) $validated['account_id'], $from, $to)
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse($e->getMessage() ?: 'Account not found.', [], 404);
        }
    }

    public function journalActivityByMonth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $to = $request->filled('date_to')
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();
        $from = $request->filled('date_from')
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : null;
        $limit = (int) ($validated['limit'] ?? 24);

        return $this->successResponse(
            $this->reports->journalActivityByMonth($from, $to, $limit)
        );
    }

    private function asOf(Request $request): Carbon
    {
        return $request->filled('as_of')
            ? Carbon::parse($request->input('as_of'))->endOfDay()
            : ($request->filled('date_to')
                ? Carbon::parse($request->input('date_to'))->endOfDay()
                : now()->endOfDay());
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $to = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : now()->endOfDay();
        $from = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $to->copy()->startOfMonth()->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
