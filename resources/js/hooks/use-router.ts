import { usePageProps } from './use-page-props';

export default function useRouter() {
    const { currentTeam, currentEnvironment, currentProject } = usePageProps();

    return {
        route: (name: string, parameter?: string) => {
            const parameters = [];
            if (name.includes('teams')) {
                parameters.push(currentTeam?.slug);
            }
            if (name.includes('projects')) {
                parameters.push(currentProject?.slug);
            }
            if (name.includes('environments')) {
                parameters.push(currentEnvironment?.id);
            }
            if (parameter) {
                parameters.push(parameter);
            }

            return route(name, parameters);
        },
    };
}
