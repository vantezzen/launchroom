import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    teams: Team[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;

    currentTeam?: Team;
    currentProject?: Project;
    currentEnvironment?: Environment;

    [key: string]: unknown;
}

export interface User {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export interface Team {
    id: string;
    name: string;
    slug: string;
    projects?: Project[];

    created_at: string;
    updated_at: string;
}

export interface Deployment {
    id: string;
    environment_id: string;
    project_id: string;
    status: 'pending' | 'running' | 'succeeded' | 'failed';
    output: string;
    is_latest: boolean;

    started_at: string;
    finished_at: string;

    created_at: string;
    updated_at: string;
}

export interface Service {
    id: string;
    name: string;
    environment_id: string;

    category: string;
    service_type: string;
    environment_variables: Record<string, string>;

    created_at: string;
    updated_at: string;
}

export interface Environment {
    id: string;
    name: string;
    project_id: string;
    type: 'production' | 'staging' | 'testing' | 'development';

    deployments: Deployment[];
    domains: string[];
    environment_variables: Record<string, string>;
    services: Service[];
    branch: string;

    created_at: string;
    updated_at: string;
}

export interface ProcessingUsage {
    id: string;
    environment_id: string;

    cpu: number; // in %
    mem_usage: number; // in bytes
    mem_limit: number; // in bytes
    mem_percent: number; // in %
    net_in: number; // in bytes
    net_out: number; // in bytes
    block_in: number; // in bytes
    block_out: number; // in bytes

    created_at: string;
    updated_at: string;
}

export interface Project {
    id: string;
    name: string;
    slug: string;

    team_id: number;
    team?: Team;

    environments: Environment[];
    repository: string;
    deployment_template?: string;

    created_at: string;
    updated_at: string;
}

export interface GithubOwner {
    login: string;
    avatar_url: string;
}

export interface Repository {
    id: number;
    name: string;
    description: string;
    full_name: string;
    private: boolean;
    html_url: string;
    language: string;
    updated_at: string;

    owner: GithubOwner;
}
