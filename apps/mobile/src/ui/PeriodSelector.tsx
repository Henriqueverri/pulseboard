import { PERIOD_PRESETS, PRESET_LABELS, type PeriodPreset } from '@/lib/period';

import { ChipGroup } from './ChipGroup';

const OPTIONS = PERIOD_PRESETS.map((preset) => ({ value: preset, label: PRESET_LABELS[preset] }));

/** Period presets shared by the dashboard and the transaction list. */
export function PeriodSelector({ value, onChange }: { value: PeriodPreset; onChange: (preset: PeriodPreset) => void }) {
  return <ChipGroup label="Período" options={OPTIONS} value={value} onChange={onChange} />;
}
