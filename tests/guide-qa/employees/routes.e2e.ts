import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { DatabaseSync } from 'node:sqlite';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

type ScreenCase = {
    id: string;
    module: string;
    page: string;
    model?: string | null;
    route_uri: string;
};

const workspace = process.cwd();
const manifest = JSON.parse(readFileSync(join(workspace, 'user-guide/content/screenshots.manifest.json'), 'utf8')) as ScreenCase[];
const tables: Record<string, string> = {
    CustomerProfile: 'customer_profiles',
    CustomerQuotationRequest: 'customer_quotation_requests',
    CustomerReturnRequest: 'customer_return_requests',
    Lead: 'leads',
    SalesOpportunity: 'sales_opportunities',
    Campaign: 'campaigns',
    PriceHistory: 'price_histories',
    PriceFloorOverride: 'price_floor_overrides',
    EmployeeProfile: 'employee_profiles',
    SalesPlan: 'sales_plans',
    PlanTask: 'plan_tasks',
    CustomerVisit: 'customer_visits',
    EmployeePerformanceScore: 'employee_performance_scores',
    EmployeeSalaryCalculation: 'employee_salary_calculations',
};

function resolveRoute(screen: ScreenCase): string | null {
    if (!screen.route_uri.includes('{record}')) {
        return '/' + screen.route_uri;
    }
    const table = tables[screen.model?.split('\\').at(-1) ?? ''];
    const file = join(workspace, 'tmp/e2e.sqlite');
    if (!table || !existsSync(file)) return null;
    const db = new DatabaseSync(file, { readOnly: true });
    try {
        const row = db.prepare('SELECT id FROM ' + table + ' ORDER BY id LIMIT 1').get() as { id?: number } | undefined;
        return row?.id === undefined ? null : '/' + screen.route_uri.replace('{record}', String(row.id));
    } finally {
        db.close();
    }
}

const screens = manifest.filter((entry) => entry.module === 'employees');
for (const screenCase of screens) {
    for (const locale of ['en', 'ar'] as const) {
        test(`EMPLOYEES route ${screenCase.id} ${locale}`, { session: 'qa-admin', timeout: 90000 }, async ({ app, browser }) => {
            const route = resolveRoute(screenCase);
            if (!route) {
                test.skip('The isolated demo has no qualifying record for this manifest route.');
            }
            await app.open('/locale/' + locale);
            await app.open(route!);
            await expect(browser).toHaveURL(new RegExp(screenCase.route_uri.replace('{record}', '[^/]+').replaceAll('/', '\\/')));
            await expect(browser.locator('h1').first()).toBeVisible();
            const title = await browser.locator('h1').first().textContent();
            expect((title ?? '').trim().length).toBeGreaterThan(0);
        });
    }
}
