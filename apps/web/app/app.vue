<script setup lang="ts">
import { TooltipProvider } from 'reka-ui'
</script>

<template>
  <TooltipProvider :delay-duration="300">
    <NuxtLoadingIndicator
      color="rgb(91 91 214)"
      :height="2"
    />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
    <UiToastHost />
  </TooltipProvider>
</template>
