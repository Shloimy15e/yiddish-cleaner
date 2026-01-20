<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { BookOpen, FileText, LayoutGrid, Upload, Settings, Sparkles, Github, ChartBar } from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: 'dashboard',
            icon: LayoutGrid,
        },
        {
            title: 'Import',
            href: 'audio-samples.create',
            icon: Upload,
        },
        {
            title: 'Audio Samples',
            href: 'audio-samples.index',
            icon: FileText,
        },
        {
            title: 'Benchmarks',
            href: 'benchmark.index',
            icon: ChartBar,
        },
    ];

    // Only show Training nav item when feature is enabled
    if ((page.props as any).features?.training) {
        items.push({
            title: 'Training',
            href: 'training.index',
            icon: Sparkles,
        });
    }

    items.push({
        title: 'Settings',
        href: 'settings.profile.edit',
        icon: Settings,
    });

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Source Code',
        href: 'https://github.com/shloi/yiddish-cleaner',
        icon: Github,
    },
    {
        title: 'Documentation',
        href: 'docs',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
