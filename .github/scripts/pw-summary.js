/**
 * Render a Playwright JSON report into a GitHub Actions Step Summary.
 *
 * Reads tests/e2e/results.json (produced by the `json` reporter in CI) and
 * appends a Markdown panel — overall verdict, a counts table, and the list of
 * failing tests — to $GITHUB_STEP_SUMMARY so it shows on the run page.
 *
 * Pure Node, no dependencies. Never throws: a missing/garbled report just
 * skips the summary rather than failing the job (the test step owns pass/fail).
 */
'use strict';

const fs = require('fs');

const REPORT = 'tests/e2e/results.json';
const wp = process.env.WP_VERSION || 'unknown';
const summaryFile = process.env.GITHUB_STEP_SUMMARY;

function appendSummary(md) {
    if (summaryFile) {
        fs.appendFileSync(summaryFile, md);
    } else {
        process.stdout.write(md);
    }
}

try {
    if (!fs.existsSync(REPORT)) {
        appendSummary(`## ⚠️ E2E — WordPress ${wp}\n\nNo \`${REPORT}\` was produced (the suite may not have started).\n`);
        process.exit(0);
    }

    const report = JSON.parse(fs.readFileSync(REPORT, 'utf8'));
    const stats = report.stats || {};
    const passed = stats.expected || 0;
    const failed = stats.unexpected || 0;
    const flaky = stats.flaky || 0;
    const skipped = stats.skipped || 0;
    const duration = ((stats.duration || 0) / 1000).toFixed(1);
    const ok = failed === 0;

    // Walk the suite tree and collect "file › test title" for every failing spec.
    const failures = [];
    const walk = (suite) => {
        (suite.suites || []).forEach(walk);
        (suite.specs || []).forEach((spec) => {
            if (spec.ok === false) {
                const file = spec.file || (suite && suite.file) || '';
                failures.push(`${file} › ${spec.title}`);
            }
        });
    };
    (report.suites || []).forEach(walk);

    let md = `## ${ok ? '✅' : '❌'} E2E — WordPress ${wp}\n\n`;
    md += '| Result | ✅ Passed | ❌ Failed | ⚠️ Flaky | ⏭️ Skipped | ⏱️ Duration |\n';
    md += '|:------:|:--------:|:--------:|:-------:|:----------:|:-----------:|\n';
    md += `| ${ok ? '**Pass**' : '**Fail**'} | ${passed} | ${failed} | ${flaky} | ${skipped} | ${duration}s |\n`;

    if (failures.length) {
        md += '\n<details open><summary><strong>Failing tests</strong></summary>\n\n';
        failures.forEach((t) => {
            md += `- \`${t}\`\n`;
        });
        md += '\n</details>\n';
    }

    md += `\n> 📦 Full HTML report (traces, screenshots, video) is attached as artifact **\`playwright-report-wp-${wp}\`**.\n`;

    appendSummary(md);
} catch (err) {
    appendSummary(`## ⚠️ E2E — WordPress ${wp}\n\nCould not parse the Playwright report: ${err.message}\n`);
}
