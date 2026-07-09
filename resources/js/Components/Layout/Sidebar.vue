<template>
  <aside
    :class="[
      'bg-slate-900 text-white overflow-hidden transition-[width] duration-300 ease-in-out',
      isMobile ? 'fixed inset-y-0 left-0 z-30' : 'flex-shrink-0',
      open ? 'w-64' : 'w-0',
    ]">
    <div class="w-64 h-full flex flex-col">

      <!-- Logo -->
      <div class="h-16 flex items-center px-4 border-b border-slate-800 flex-shrink-0">
        <template v-if="companyLogo">
          <img :src="companyLogo" :alt="companyName"
            class="h-9 w-9 rounded-lg object-contain mr-3 bg-white p-0.5 flex-shrink-0" />
        </template>
        <template v-else>
          <div class="w-9 h-9 bg-primary-500 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5" />
            </svg>
          </div>
        </template>
        <span class="font-bold text-sm leading-tight line-clamp-2">{{ companyName }}</span>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 py-3 overflow-y-auto space-y-0.5">
        <NavItem :href="route('dashboard')" icon="home">Dashboard</NavItem>
        <NavItem :href="route('notifications.index')" icon="bell">Thông báo</NavItem>

        <!-- Core Menus (Catalog, Sales, Purchasing, Projects, Technical Support, Warehouse) -->
        <template v-for="menu in coreMenus" :key="menu.key">
          <template v-if="!menu.route_name && menu.children && menu.children.length > 0">
            <NavGroup :label="menu.label" :icon="menu.icon" :prefix="'/' + menu.key.split('.')[0]">
              <NavItem
                v-for="child in menu.children"
                :key="child.key"
                :href="route(child.route_name)"
                :icon="child.icon"
                sub
              >
                {{ child.label }}
              </NavItem>
            </NavGroup>
          </template>
          <template v-else-if="menu.route_name">
            <NavItem :href="route(menu.route_name)" :icon="menu.icon">
              {{ menu.label }}
            </NavItem>
          </template>
        </template>

        <!-- Kế toán Section -->
        <div v-if="accountingMenus.length > 0" class="mt-3 pt-3 border-t border-slate-800">
          <p class="px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Kế toán</p>
          <template v-for="menu in accountingMenus" :key="menu.key">
            <template v-if="!menu.route_name && menu.children && menu.children.length > 0">
              <NavGroup :label="menu.label" :icon="menu.icon" :prefix="'/' + menu.key.replace('.', '/')">
                <NavItem
                  v-for="child in menu.children"
                  :key="child.key"
                  :href="route(child.route_name)"
                  :icon="child.icon"
                  sub
                >
                  {{ child.label }}
                </NavItem>
              </NavGroup>
            </template>
          </template>
        </div>

        <!-- Quản trị Section -->
        <div v-if="adminMenus.length > 0" class="mt-3 pt-3 border-t border-slate-800">
          <p class="px-4 text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Quản trị</p>
          <template v-for="menu in adminMenus" :key="menu.key">
            <template v-if="!menu.route_name && menu.children && menu.children.length > 0">
              <NavGroup :label="menu.label" :icon="menu.icon" :prefix="'/' + menu.key.replace('_', '/')">
                <NavItem
                  v-for="child in menu.children"
                  :key="child.key"
                  :href="route(child.route_name)"
                  :icon="child.icon"
                  sub
                >
                  {{ child.label }}
                </NavItem>
              </NavGroup>
            </template>
          </template>
        </div>
      </nav>

    </div>
  </aside>
</template>

<script setup>
import { computed, provide, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import NavItem from './NavItem.vue';
import NavGroup from './NavGroup.vue';

const props = defineProps({
  open:     Boolean,
  isMobile: Boolean,
});
defineEmits(['close']);

const page        = usePage();
const company     = computed(() => page.props.company ?? {});
const companyName = computed(() => company.value.company_name || 'Mini ERP');
const companyLogo = computed(() => company.value.company_logo || null);
const menuItems   = computed(() => page.props.menuItems || []);

// Group menus into sections
const coreMenus = computed(() => {
  return menuItems.value.filter(
    m => !m.key.startsWith('accounting') && m.key !== 'admin_group'
  );
});

const accountingMenus = computed(() => {
  return menuItems.value.filter(m => m.key.startsWith('accounting'));
});

const adminMenus = computed(() => {
  return menuItems.value.filter(m => m.key === 'admin_group');
});

// Accordion context: only one NavGroup open at a time
const openPrefix = ref(null);
provide('sidebarAccordion', {
  openPrefix,
  setOpen: (prefix) => { openPrefix.value = prefix; },
});
</script>
