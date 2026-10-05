<?php

namespace App\Http\Requests\Insights;

use App\Http\Requests\Analytics\AnalyticsRequest;

/**
 * The same period contract as the dashboard (from/to in the organization's
 * timezone, 30 days by default, at most 366, no organization_id). There is no
 * free text: the summary input is the period only.
 */
class PeriodSummaryRequest extends AnalyticsRequest {}
