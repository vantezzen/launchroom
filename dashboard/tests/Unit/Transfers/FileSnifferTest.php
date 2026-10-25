<?php

declare(strict_types=1);

use App\Services\Transfers\FileSniffer;
use Tests\Support\Transfers;

/*
| docs/14 §12.3: the dashboard recognises a dump from its first bytes and
| refuses one newer than the target, naming both versions.
*/

test('a pg_dump custom file carries the source server and pg_dump versions', function (): void {
    $result = new FileSniffer()->database(Transfers::pgCustom('15.8 (Debian 15.8-1.pgdg120+1)', '15.8'));

    expect($result->format)->toBe('postgres_custom')
        ->and($result->family)->toBe('postgres')
        ->and($result->serverVersion)->toBe('15.8')
        ->and($result->toolVersion)->toBe('15.8')
        ->and($result->detail())->toBe('PostgreSQL custom dump from PostgreSQL 15.8')
        ->and(FileSniffer::refusal($result, 'postgres', '17', 'postgres'))->toBeNull();
});

test('a dump newer than the target is refused, naming both versions', function (): void {
    $sniffer = new FileSniffer;

    expect(FileSniffer::refusal($sniffer->database(Transfers::pgCustom('18.1', '18.1')), 'postgres', '17', 'postgres'))
        ->toContain('PostgreSQL 18.1')->toContain('PostgreSQL 17')
        ->and(FileSniffer::refusal($sniffer->database(Transfers::pgCustom('16.4', '18.1')), 'postgres', '17', 'postgres'))
        ->toContain('pg_dump 18.1')->toContain('17')
        ->and(FileSniffer::refusal($sniffer->database(Transfers::pgCustom('9.6.24', '9.6.24')), 'postgres', '16', 'db'))->toBeNull()
        ->and(FileSniffer::refusal($sniffer->database(Transfers::mongoArchive('8.0.4')), 'mongodb', '7.0', 'mongo'))
        ->toContain('MongoDB 8.0.4')->toContain('MongoDB 7.0');
});

test('plain SQL dumps, gzipped or not, are recognised by their header', function (): void {
    $sniffer = new FileSniffer;
    $pg = "--\n-- PostgreSQL database dump\n--\n\n-- Dumped from database version 16.4\n-- Dumped by pg_dump version 16.4\n\nSET statement_timeout = 0;\n";
    $mysql = "-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)\n--\n-- Host: localhost    Database: app\n-- ------------------------------------------------------\n-- Server version\t8.0.36\n";
    $maria = "/*M!999999\\- enable the sandbox mode */ \n-- MariaDB dump 10.19-11.4.2-MariaDB, for debian-linux-gnu (x86_64)\n--\n-- Server version\t11.4.2-MariaDB-ubu2404\n";

    $gz = $sniffer->database((string) gzencode($pg));
    expect($gz->format)->toBe('postgres_sql')
        ->and($gz->compressed)->toBeTrue()
        ->and($gz->serverVersion)->toBe('16.4')
        ->and($gz->detail())->toBe('Gzipped PostgreSQL SQL dump from PostgreSQL 16.4')
        ->and($sniffer->database($mysql)->family)->toBe('mysql')
        ->and($sniffer->database($mysql)->serverVersion)->toBe('8.0.36')
        ->and(FileSniffer::refusal($sniffer->database($mysql), 'mysql', '8.4', 'mysql'))->toBeNull()
        ->and($sniffer->database($maria)->family)->toBe('mariadb')
        ->and(FileSniffer::refusal($sniffer->database($maria), 'mysql', '8.4', 'mysql'))->toBeNull()
        ->and(FileSniffer::refusal($sniffer->database(str_replace('8.0.36', '9.1.0', $mysql)), 'mysql', '8.4', 'mysql'))->toContain('MySQL 9.1.0');
});

test('wrong engines, cluster dumps and unknown files are refused clearly', function (): void {
    $sniffer = new FileSniffer;

    expect(FileSniffer::refusal($sniffer->database(Transfers::pgCustom()), 'mysql', '8.4', 'mysql'))->toContain('but mysql is MySQL')
        ->and(FileSniffer::refusal($sniffer->database("--\n-- PostgreSQL database cluster dump\n--\n"), 'postgres', '17', 'postgres'))->toContain('pg_dumpall')
        ->and(FileSniffer::refusal($sniffer->database('hello world'), 'postgres', '17', 'postgres'))->toContain('couldn’t recognise')
        ->and($sniffer->database(Transfers::mongoArchive('7.0.5'))->serverVersion)->toBe('7.0.5');
});

test('tar, tar.gz and zip archives are recognised with their first entries', function (): void {
    $dir = sys_get_temp_dir().'/lr-sniff-'.bin2hex(random_bytes(4));
    mkdir($dir.'/uploads/a', 0755, true);
    file_put_contents($dir.'/uploads/a/one.txt', 'one');
    file_put_contents($dir.'/uploads/two.txt', 'two');

    $tar = new PharData($dir.'/x.tar');
    $tar->buildFromDirectory($dir, '#uploads#');
    $tarBytes = (string) file_get_contents($dir.'/x.tar');

    $zip = new ZipArchive;
    $zip->open($dir.'/x.zip', ZipArchive::CREATE);
    $zip->addFromString('uploads/two.txt', 'two');
    $zip->addFromString('uploads/a/one.txt', 'one');
    $zip->close();
    $zipBytes = (string) file_get_contents($dir.'/x.zip');

    $sniffer = new FileSniffer;
    $plain = $sniffer->archive($tarBytes);
    $gz = $sniffer->archive((string) gzencode($tarBytes));
    $zipped = $sniffer->archive($zipBytes, $zipBytes);

    expect($plain->format)->toBe('tar')
        ->and($plain->entries)->toContain('uploads/two.txt')
        ->and($plain->topFolder())->toBe('uploads')
        ->and($gz->format)->toBe('tar')->and($gz->compressed)->toBeTrue()
        ->and($zipped->format)->toBe('zip')
        ->and($zipped->entries)->toBe(['uploads/two.txt', 'uploads/a/one.txt'])
        ->and($zipped->entriesComplete)->toBeTrue()
        ->and($zipped->topFolder())->toBe('uploads')
        ->and($sniffer->archive('not an archive')->format)->toBeNull();
});
