<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpen, FileText, LayoutGrid, Play, Settings, Sparkles, Github } from 'lucide-vue-next';

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

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: 'dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Process',
        href: 'process.index',
        icon: Play,
    },
    {
        title: 'Documents',
        href: 'documents.index',
        icon: FileText,
    },
    {
        title: 'Training',
        href: 'training.index',
        icon: Sparkles,
    },
    {
        title: 'Settings',
        href: 'settings.profile.edit',
        icon: Settings,
    },
];

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
