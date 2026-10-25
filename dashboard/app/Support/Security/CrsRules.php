<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\WafCategory;

/**
 * Messages of the OWASP Core Rule Set rules users meet most (CRS 4.x,
 * pinned in launchroom-caddy, D64), for the event sheet and the
 * exceptions list. Other rules read as their category and id. Pure.
 */
final class CrsRules
{
    private const array MESSAGES = [
        911100 => 'Method is not allowed by policy',
        913100 => 'Found User-Agent associated with security scanner',
        913110 => 'Found request header associated with security scanner',
        913120 => 'Found request filename/argument associated with security scanner',
        920100 => 'Invalid HTTP request line',
        920170 => 'GET or HEAD request with body content',
        920180 => 'POST without Content-Length or Transfer-Encoding headers',
        920220 => 'URL encoding abuse attack attempt',
        920230 => 'Multiple URL encoding detected',
        920240 => 'URL encoding abuse attack attempt',
        920270 => 'Invalid character in request (null character)',
        920271 => 'Invalid character in request (non printable characters)',
        920280 => 'Request missing a Host header',
        920300 => 'Request missing an Accept header',
        920320 => 'Missing User Agent header',
        920340 => 'Request containing content, but missing Content-Type header',
        920341 => 'Request containing content requires Content-Type header',
        920350 => 'Host header is a numeric IP address',
        920420 => 'Request content type is not allowed by policy',
        920440 => 'URL file extension is restricted by policy',
        920450 => 'HTTP header is restricted by policy',
        920480 => 'Request content type charset is not allowed by policy',
        921110 => 'HTTP request smuggling attack',
        921120 => 'HTTP response splitting attack',
        921130 => 'HTTP response splitting attack',
        921150 => 'HTTP header injection attack via payload (CR/LF detected)',
        930100 => 'Path traversal attack (/../) or (/.../)',
        930110 => 'Path traversal attack (/../) or (/.../)',
        930120 => 'OS file access attempt',
        930130 => 'Restricted file access attempt',
        931100 => 'Possible remote file inclusion (RFI) attack: URL parameter using IP address',
        931110 => 'Possible remote file inclusion (RFI) attack: common RFI vulnerable parameter name used with URL payload',
        931120 => 'Possible remote file inclusion (RFI) attack: URL payload used with trailing question mark character (?)',
        932100 => 'Remote command execution: Unix command injection',
        932105 => 'Remote command execution: Unix command injection',
        932110 => 'Remote command execution: Windows command injection',
        932115 => 'Remote command execution: Windows command injection',
        932120 => 'Remote command execution: Windows PowerShell command found',
        932130 => 'Remote command execution: Unix shell expression found',
        932150 => 'Remote command execution: direct Unix command execution',
        932160 => 'Remote command execution: Unix shell code found',
        932170 => 'Remote command execution: Shellshock (CVE-2014-6271)',
        932180 => 'Restricted file upload attempt',
        933100 => 'PHP injection attack: PHP open tag found',
        933110 => 'PHP injection attack: PHP script file upload found',
        933120 => 'PHP injection attack: configuration directive found',
        933130 => 'PHP injection attack: variables found',
        933150 => 'PHP injection attack: high-risk PHP function name found',
        933160 => 'PHP injection attack: high-risk PHP function call found',
        934100 => 'Node.js injection attack',
        934110 => 'Possible server side request forgery (SSRF) attack: cloud provider metadata URL in parameter',
        934130 => 'JavaScript prototype pollution',
        941100 => 'XSS attack detected via libinjection',
        941110 => 'XSS filter: script tag vector',
        941120 => 'XSS filter: event handler vector',
        941130 => 'XSS filter: attribute vector',
        941140 => 'XSS filter: JavaScript URI vector',
        941160 => 'NoScript XSS InjectionChecker: HTML injection',
        941170 => 'NoScript XSS InjectionChecker: attribute injection',
        941180 => 'Node-validator deny list keywords',
        942100 => 'SQL injection attack detected via libinjection',
        942110 => 'SQL injection attack: common injection testing detected',
        942120 => 'SQL injection attack: SQL operator detected',
        942130 => 'SQL injection attack: SQL boolean-based attack detected',
        942140 => 'SQL injection attack: common DB names detected',
        942150 => 'SQL injection attack: SQL function names detected',
        942160 => 'Detects blind SQL injection tests using sleep() or benchmark()',
        942170 => 'Detects SQL benchmark and sleep injection attempts including conditional queries',
        942190 => 'Detects MSSQL code execution and information gathering attempts',
        942200 => 'Detects MySQL comment-/space-obfuscated injections and backtick termination',
        942260 => 'Detects basic SQL authentication bypass attempts',
        942270 => 'Looking for basic SQL injection: common attack string for MySQL, Oracle and others',
        942340 => 'Detects basic SQL authentication bypass attempts',
        942360 => 'Detects concatenated basic SQL injection and SQLLFI attempts',
        942430 => 'Restricted SQL character anomaly detection (args): number of special characters exceeded',
        942440 => 'SQL comment sequence detected',
        943100 => 'Possible session fixation attack: setting cookie values in HTML',
        943110 => 'Possible session fixation attack: session ID parameter name with off-domain referrer',
        944100 => 'Remote command execution: suspicious Java class detected',
        944110 => 'Remote command execution: Java process spawn (CVE-2017-9805)',
        944150 => 'Potential remote command execution: Log4j / Log4shell',
    ];

    public static function message(int $rule): string
    {
        return self::MESSAGES[$rule] ?? WafCategory::ofRule($rule)->label()." rule {$rule}";
    }
}
