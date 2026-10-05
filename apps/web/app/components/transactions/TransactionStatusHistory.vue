<script setup lang="ts">
import { PhArrowRight } from '@phosphor-icons/vue'
import { TRANSACTION_SOURCE_LABELS, TRANSACTION_STATUS_LABELS } from '~/types/transaction'
import type { TransactionStatus, TransactionStatusChange } from '~/types/transaction'

const props = defineProps<{
  /** `status_history` exactly as returned by the API (already in lifecycle order). */
  history: TransactionStatusChange[]
  timezone: string
}>()

const DOT: Record<TransactionStatus, string> = {
  paid: 'bg-success',
  pending: 'bg-warning',
  refunded: 'bg-info',
  canceled: 'bg-danger',
}

/** Below this gap the recording time adds nothing next to the business time. */
const RECORDING_GAP_MS = 60_000

const steps = computed(() => props.history.map((change, index) => ({
  key: `${change.from_status ?? 'new'}-${change.to_status}`,
  change,
  title: change.from_status === null
    ? `Criada como ${TRANSACTION_STATUS_LABELS[change.to_status]}`
    : `${TRANSACTION_STATUS_LABELS[change.from_status]} → ${TRANSACTION_STATUS_LABELS[change.to_status]}`,
  current: index === props.history.length - 1,
  occurredAt: formatDateTime(change.occurred_at, props.timezone, { time: true }),
  recordedAt: Math.abs(Date.parse(change.recorded_at) - Date.parse(change.occurred_at)) >= RECORDING_GAP_MS
    ? formatDateTime(change.recorded_at, props.timezone, { time: true })
    : null,
})))
</script>

<template>
  <div>
    <UiEmptyState
      v-if="!history.length"
      title="Sem histórico de status"
      description="A API não retornou mudanças de status para esta transação."
      compact
    />

    <template v-else>
      <ol
        class="flex flex-wrap items-center gap-1.5 text-xs"
        aria-label="Sequência de status"
      >
        <li class="flex items-center gap-1.5 font-mono text-ink/65">
          null
        </li>
        <li
          v-for="step in steps"
          :key="step.key"
          class="flex items-center gap-1.5"
        >
          <PhArrowRight
            :size="12"
            class="text-ink/40"
            aria-hidden="true"
          />
          <StatusBadge
            :status="step.change.to_status"
            size="sm"
          />
        </li>
      </ol>

      <ol
        class="mt-5"
        aria-label="Histórico de status"
      >
        <li
          v-for="(step, index) in steps"
          :key="step.key"
          class="relative flex gap-3 pb-5 last:pb-0"
          :aria-current="step.current ? 'step' : undefined"
        >
          <span
            v-if="index < steps.length - 1"
            class="absolute left-[7px] top-5 h-[calc(100%-1.25rem)] w-px bg-ink/10"
            aria-hidden="true"
          />
          <span
            class="relative mt-0.5 flex size-[15px] shrink-0 items-center justify-center rounded-full"
            :class="step.current ? 'bg-surface ring-2 ring-ink/80' : 'bg-surface ring-1 ring-ink/15'"
            aria-hidden="true"
          >
            <span
              class="size-[7px] rounded-full"
              :class="DOT[step.change.to_status]"
            />
          </span>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
              <p class="text-sm font-medium text-ink">
                {{ step.title }}
              </p>
              <UiTag
                v-if="step.current"
                tone="brand"
                size="sm"
              >
                Status atual
              </UiTag>
            </div>
            <p class="mt-0.5 text-xs text-ink/65">
              <span class="tabular">{{ step.occurredAt }}</span>
              · {{ TRANSACTION_SOURCE_LABELS[step.change.source] }}
            </p>
            <p
              v-if="step.recordedAt"
              class="text-xs text-ink/65"
            >
              Registrada em <span class="tabular">{{ step.recordedAt }}</span>
            </p>
          </div>
        </li>
      </ol>
    </template>
  </div>
</template>
