<script setup lang="ts">
import type { Product } from '~/types/product'
import { errorMessage } from '~/utils/api-error'

const props = defineProps<{ product: Pick<Product, 'id' | 'name'> | null }>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ deleted: [] }>()

const mutations = useProductMutations()
const toast = useToast()
const pending = ref(false)

async function onConfirm() {
  if (!props.product) {
    return
  }

  pending.value = true
  try {
    await mutations.remove(props.product)
    toast.success('Produto excluído', props.product.name)
    open.value = false
    emit('deleted')
  }
  catch (error) {
    toast.error('Não foi possível excluir o produto', errorMessage(error))
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <UiConfirmDialog
    v-model:open="open"
    :title="`Excluir ${product?.name ?? 'produto'}?`"
    description="Se o produto já tiver vendas, ele é arquivado e o histórico das transações é preservado. Sem vendas, ele é removido definitivamente."
    confirm-label="Excluir produto"
    tone="danger"
    :loading="pending"
    @confirm="onConfirm"
  />
</template>
