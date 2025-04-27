export interface DomainValidationResult {
    valid: boolean;
    is_development: boolean;
    error: string | null;
    expected_ip?: string;
    actual_ips?: string[];
    message?: string;
}
