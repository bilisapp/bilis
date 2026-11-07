import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { switchMethod } from '@/routes/teams';
import type { Team } from '@/types';

/**
 * Switch the current team and stay on the same page in the new one.
 *
 * Team-scoped URLs carry the slug (`/acme/logs`), so after the switch the
 * current path is re-visited with the new slug swapped in; a page that is not
 * team-scoped is simply reloaded. Shared by every control that switches.
 */
export function useTeamSwitch() {
    const page = usePage();

    const currentTeam = computed(() => page.props.currentTeam);
    const teams = computed(() => page.props.teams ?? []);

    function switchTeam(team: Team): void {
        const previousTeamSlug = currentTeam.value?.slug;

        router.visit(switchMethod(team.slug), {
            onFinish: () => {
                if (!previousTeamSlug || typeof window === 'undefined') {
                    router.reload();

                    return;
                }

                const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
                const segment = `/${previousTeamSlug}`;

                if (currentUrl.includes(segment)) {
                    router.visit(currentUrl.replace(segment, `/${team.slug}`), {
                        replace: true,
                    });

                    return;
                }

                router.reload();
            },
        });
    }

    return { currentTeam, teams, switchTeam };
}
