<script setup lang="ts">
import type { Customer } from '~/types/customer'
import { errorMessage } from '~/utils/api-error'

const props = defineProps<{ customer: Pick<Customer, 'id' | 'name'> | null }>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ deleted: [] }>()

const mutations = useCustomerMutations()
const toast = useToast()
const pending = ref(false)

async function onConfirm() {
  if (!props.customer) {
    return
  }

  pending.value = true
  try {
    await mutations.remove(props.customer)
    toast.success('Cliente excluído', props.customer.name)
    open.value = false
    emit('deleted')
  }
  catch (error) {
    toast.error('Não foi possível excluir o cliente', errorMessage(error))
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <UiConfirmDialog
    v-model:open="open"
    :title="`Excluir ${customer?.name ?? 'cliente'}?`"
    description="Se o cliente já tiver transações, ele é arquivado e continua aparecendo no histórico como removido. Sem transações, ele é excluído definitivamente. O e-mail continua reservado nesta organização."
    confirm-label="Excluir cliente"
    tone="danger"
    :loading="pending"
    @confirm="onConfirm"
  />
</template>
