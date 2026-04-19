#!/usr/bin/env node
/**
 * LinkedIn CDP Helper
 *
 * Connects to a running Chrome instance via CDP and executes
 * LinkedIn Voyager API operations using the authenticated session.
 *
 * Usage:
 *   node linkedin-cdp.js <action> [json_params]
 *
 * Actions:
 *   session_check                    - Check if LinkedIn session is valid
 *   search_companies <json>          - Search for companies
 *   search_people <json>             - Search for people
 *   get_company_profile <json>       - Get company details by URL
 *   get_person_profile <json>        - Get person details by URL
 *
 * All output is JSON to stdout. Errors are JSON with { error: "message" }.
 */

const { chromium } = require('playwright');

const CDP_URL = process.env.LINKEDIN_CDP_URL || 'http://127.0.0.1:9222';

// LinkedIn geoUrn codes
const GEO_URNS = {
    'US': '103644278',
    'UK': '101165590',
    'CA': '101174742',
    'AU': '101452733',
    'DE': '101282230',
};

// Industry keywords that indicate product companies (not service providers)
const PRODUCT_INDUSTRIES = /(retail|consumer goods|food|beverage|apparel|fashion|beauty|cosmetics|health|wellness|furniture|home|pet|sport|outdoor|supplement|skincare|clothing|jewelry|toy|personal care|luxury goods|manufacturing|farming)/i;

// Keywords that indicate service providers to filter out
const SERVICE_KEYWORDS = /(agency|consult|marketing services|advertising services|it services|software dev|web design|freelanc|development company|digital solution|staffing|recruiting|managed service|3pl|fulfillment|logistics|warehousing)/i;

async function connectBrowser() {
    try {
        const browser = await chromium.connectOverCDP(CDP_URL);
        const context = browser.contexts()[0];
        if (!context) throw new Error('No browser context found');
        const page = context.pages()[0];
        if (!page) throw new Error('No page found');
        return { browser, context, page };
    } catch (e) {
        throw new Error(`Cannot connect to Chrome CDP at ${CDP_URL}: ${e.message}`);
    }
}

async function getCsrfToken(page) {
    return page.evaluate(() => {
        const match = document.cookie.match(/JSESSIONID="?([^;"]+)/);
        return match ? match[1] : null;
    });
}

async function voyagerFetch(page, url) {
    const csrfToken = await getCsrfToken(page);
    if (!csrfToken) throw new Error('No CSRF token — LinkedIn session may be expired');

    return page.evaluate(async ({ url, csrfToken }) => {
        const resp = await fetch(url, {
            headers: {
                'csrf-token': csrfToken,
                'x-restli-protocol-version': '2.0.0',
                'x-li-lang': 'en_US',
            }
        });
        if (!resp.ok) {
            return { _error: true, status: resp.status, body: await resp.text().then(t => t.substring(0, 500)) };
        }
        return resp.json();
    }, { url, csrfToken });
}

// ─── Actions ──────────────────────────────────────────────────────────────────

async function sessionCheck(page) {
    const csrfToken = await getCsrfToken(page);
    if (!csrfToken) return { valid: false, reason: 'No CSRF token' };

    const cookies = await page.context().cookies('https://www.linkedin.com');
    const liAt = cookies.find(c => c.name === 'li_at');
    if (!liAt) return { valid: false, reason: 'No li_at cookie' };

    // Quick API call to verify session
    const result = await voyagerFetch(page,
        'https://www.linkedin.com/voyager/api/voyagerGlobalAlerts?adHocAlerts=true&alertWithActions=true&q=findAlerts'
    );

    if (result._error) {
        return { valid: false, reason: `API returned ${result.status}` };
    }

    return { valid: true, cookieExpiry: liAt.expires };
}

async function searchCompanies(page, params) {
    const {
        keywords,
        country = 'US',
        count = 10,
        start = 0,
        productOnly = true,
    } = params;

    const geoUrn = GEO_URNS[country.toUpperCase()] || GEO_URNS['US'];
    const geoParam = `geoUrn:List(${geoUrn})`;

    const url = 'https://www.linkedin.com/voyager/api/search/dash/clusters'
        + '?decorationId=com.linkedin.voyager.dash.deco.search.SearchClusterCollection-186'
        + '&origin=SWITCH_SEARCH_VERTICAL'
        + '&q=all'
        + '&query=(keywords:' + encodeURIComponent(keywords)
        + ',flagshipSearchIntent:SEARCH_SRP'
        + ',queryParameters:(resultType:List(COMPANIES),' + geoParam + '))'
        + '&start=' + start
        + '&count=' + count;

    const json = await voyagerFetch(page, url);
    if (json._error) throw new Error(`Voyager API error: ${json.status}`);

    const companies = [];
    for (const cluster of (json.elements || [])) {
        for (const item of (cluster.items || [])) {
            const entity = item.itemUnion?.entityResult;
            if (!entity) continue;

            const title = entity.title?.text || '';
            const industry = entity.primarySubtitle?.text || '';
            const summary = entity.summary?.text || '';
            const combined = title + ' ' + industry + ' ' + summary;

            // Filter logic
            const isService = SERVICE_KEYWORDS.test(combined);
            const isProduct = PRODUCT_INDUSTRIES.test(industry);

            if (productOnly && (isService || !isProduct)) continue;

            // Parse location from industry field ("Retail • City, State")
            const parts = industry.split(' • ');
            const industryName = parts[0] || '';
            const location = parts[1] || '';

            companies.push({
                name: title,
                industry: industryName.trim(),
                location: location.trim(),
                followers: entity.secondarySubtitle?.text || '',
                summary: (summary || '').substring(0, 300),
                linkedinUrl: entity.navigationUrl || '',
            });
        }
    }

    return {
        total: json.metadata?.totalResultCount || 0,
        start,
        count,
        keywords,
        country,
        productOnly,
        results: companies,
    };
}

async function searchPeople(page, params) {
    const {
        keywords,
        company = null,
        country = 'US',
        count = 10,
        start = 0,
    } = params;

    const geoUrn = GEO_URNS[country.toUpperCase()] || GEO_URNS['US'];
    let queryParams = `resultType:List(PEOPLE),geoUrn:List(${geoUrn})`;
    if (company) {
        // If we have a company name, include it in keywords
    }

    const searchKeywords = company ? `${keywords} ${company}` : keywords;

    const url = 'https://www.linkedin.com/voyager/api/search/dash/clusters'
        + '?decorationId=com.linkedin.voyager.dash.deco.search.SearchClusterCollection-186'
        + '&origin=SWITCH_SEARCH_VERTICAL'
        + '&q=all'
        + '&query=(keywords:' + encodeURIComponent(searchKeywords)
        + ',flagshipSearchIntent:SEARCH_SRP'
        + ',queryParameters:(' + queryParams + '))'
        + '&start=' + start
        + '&count=' + count;

    const json = await voyagerFetch(page, url);
    if (json._error) throw new Error(`Voyager API error: ${json.status}`);

    const people = [];
    for (const cluster of (json.elements || [])) {
        for (const item of (cluster.items || [])) {
            const entity = item.itemUnion?.entityResult;
            if (!entity) continue;

            const name = entity.title?.text || '';
            const headline = entity.primarySubtitle?.text || '';
            const location = entity.secondarySubtitle?.text || '';
            const summary = entity.summary?.text || '';

            people.push({
                name,
                headline,
                location,
                summary: (summary || '').substring(0, 300),
                linkedinUrl: entity.navigationUrl || '',
            });
        }
    }

    return {
        total: json.metadata?.totalResultCount || 0,
        start,
        count,
        keywords: searchKeywords,
        country,
        results: people,
    };
}

async function getCompanyProfile(page, params) {
    const { url: companyUrl } = params;

    // Navigate to company page and extract data
    await page.goto(companyUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    // Check we're still logged in
    if (page.url().includes('/login')) {
        throw new Error('LinkedIn session expired — need to re-authenticate');
    }

    const data = await page.evaluate(() => {
        const getText = (sel) => {
            const el = document.querySelector(sel);
            return el ? el.innerText.trim() : '';
        };

        // Extract from the about section and header
        const main = document.querySelector('main');
        const text = main ? main.innerText : '';

        // Try to get structured data from the page
        const name = getText('h1') || '';
        const tagline = getText('.org-top-card-summary__tagline') || '';

        // Parse the details section
        const details = {};
        const detailItems = document.querySelectorAll('.org-top-card-summary-info-list__info-item, dt, .org-page-details__definition-text');
        detailItems.forEach(item => {
            const t = item.innerText.trim();
            if (t) {
                if (/employees/i.test(t)) details.employees = t;
                else if (/followers/i.test(t)) details.followers = t;
                else if (/@|\.com|\.org/i.test(t) && !details.website) details.website = t;
            }
        });

        // Get about text
        const aboutSection = document.querySelector('[class*="org-about-us"]') ||
            document.querySelector('#about');
        const about = aboutSection ? aboutSection.innerText.substring(0, 500) : '';

        return {
            name,
            tagline,
            about,
            details,
            pageText: text.substring(0, 2000),
        };
    });

    return data;
}

async function getPersonProfile(page, params) {
    const { url: profileUrl } = params;

    await page.goto(profileUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    if (page.url().includes('/login')) {
        throw new Error('LinkedIn session expired — need to re-authenticate');
    }

    const data = await page.evaluate(() => {
        const getText = (sel) => {
            const el = document.querySelector(sel);
            return el ? el.innerText.trim() : '';
        };

        const name = getText('h1') || '';
        const headline = getText('.text-body-medium') || '';
        const location = getText('.text-body-small .inline') || '';

        // Get about/summary
        const aboutSection = document.querySelector('#about');
        const about = aboutSection ? aboutSection.closest('section')?.innerText?.substring(0, 500) : '';

        // Get experience
        const expSection = document.querySelector('#experience');
        const experience = expSection ? expSection.closest('section')?.innerText?.substring(0, 800) : '';

        return { name, headline, location, about, experience };
    });

    return data;
}

async function searchEmployees(page, params) {
    const {
        company_name,
        company_linkedin_url = null,
        country = 'US',
        count = 10,
        start = 0,
    } = params;

    if (!company_name) throw new Error('company_name is required');

    const geoUrn = GEO_URNS[country.toUpperCase()] || GEO_URNS['US'];

    // Search for people at the company
    const keywords = company_name;
    const url = 'https://www.linkedin.com/voyager/api/search/dash/clusters'
        + '?decorationId=com.linkedin.voyager.dash.deco.search.SearchClusterCollection-186'
        + '&origin=SWITCH_SEARCH_VERTICAL'
        + '&q=all'
        + '&query=(keywords:' + encodeURIComponent(keywords)
        + ',flagshipSearchIntent:SEARCH_SRP'
        + ',queryParameters:(resultType:List(PEOPLE),geoUrn:List(' + geoUrn + ')))'
        + '&start=' + start
        + '&count=' + count;

    const json = await voyagerFetch(page, url);
    if (json._error) throw new Error(`Voyager API error: ${json.status}`);

    const people = [];
    for (const cluster of (json.elements || [])) {
        for (const item of (cluster.items || [])) {
            const entity = item.itemUnion?.entityResult;
            if (!entity) continue;

            const name = entity.title?.text || '';
            const headline = entity.primarySubtitle?.text || '';
            const location = entity.secondarySubtitle?.text || '';
            const summary = entity.summary?.text || '';

            // Check if this person actually works at the company
            // Must match the company name as a PHRASE (not scattered words)
            const headlineLower = headline.toLowerCase();
            const summaryLower = (summary || '').toLowerCase();
            const companyLower = company_name.toLowerCase().trim();

            // Build phrase variants to check against
            // "Small Batch Jam Co" -> check: "small batch jam co", "small batch jam", etc.
            const variants = [companyLower];
            // Strip common suffixes for a shorter variant
            const stripped = companyLower
                .replace(/\b(inc|llc|ltd|co\.?|corp|company|group)\b\.?/g, '').trim();
            if (stripped !== companyLower && stripped.length > 3) {
                variants.push(stripped);
            }

            // Also try the LinkedIn company slug from the URL if available
            const companyLinkedInUrl = params.company_linkedin_url || '';
            if (companyLinkedInUrl) {
                const slug = companyLinkedInUrl.replace(/\/$/, '').split('/').pop();
                // Convert slug to readable: "small-batch-jam-co" -> "small batch jam co"
                const slugWords = slug.replace(/-/g, ' ');
                if (slugWords.length > 3 && !variants.includes(slugWords)) {
                    variants.push(slugWords);
                }
            }

            // Check if ANY variant appears as a phrase in headline or summary
            const worksHere = variants.some(v =>
                headlineLower.includes(v) || summaryLower.includes(v)
            );

            people.push({
                name,
                headline,
                location,
                summary: (summary || '').substring(0, 300),
                linkedinUrl: entity.navigationUrl || '',
                worksHere,
            });
        }
    }

    return {
        total: json.metadata?.totalResultCount || 0,
        company: company_name,
        results: people,
    };
}

async function getCompanyJobs(page, params) {
    const { company_linkedin_url } = params;
    if (!company_linkedin_url) throw new Error('company_linkedin_url is required');

    // Extract company slug from URL
    const slug = company_linkedin_url.replace(/\/$/, '').split('/').pop();

    // Navigate to the company's jobs page
    const jobsUrl = `https://www.linkedin.com/company/${slug}/jobs/`;
    await page.goto(jobsUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);

    if (page.url().includes('/login')) {
        throw new Error('LinkedIn session expired');
    }

    const data = await page.evaluate((companySlug) => {
        const main = document.querySelector('main');
        const text = main ? main.innerText : '';

        // Try to find job count
        const countMatch = text.match(/(\d[\d,]*)\s*(?:open\s+)?(?:job|position|role)/i);
        const jobCount = countMatch ? parseInt(countMatch[1].replace(/,/g, '')) : 0;

        // Extract job titles from the page
        const jobs = [];
        const jobCards = document.querySelectorAll('.job-card-container, .jobs-search-results-list__list-item, li');
        for (const card of jobCards) {
            const titleEl = card.querySelector('a[href*="/jobs/"]') || card.querySelector('h3, .job-card-list__title');
            if (titleEl) {
                const title = titleEl.innerText.trim();
                if (title && title.length < 100 && !title.match(/^\d+$/)) {
                    const locationEl = card.querySelector('.job-card-container__metadata-item, .artdeco-entity-lockup__subtitle');
                    jobs.push({
                        title,
                        location: locationEl ? locationEl.innerText.trim() : '',
                        url: titleEl.href || '',
                    });
                }
            }
        }

        // Look for hiring signals
        const hiringSignals = {
            activelyHiring: /actively hiring/i.test(text),
            recentlyHired: text.match(/(\d+)\s*(?:people\s+)?(?:were\s+)?hired/i)?.[1] || null,
            growthText: text.match(/(?:grew|growth|expanded|growing)[^.]{0,100}/i)?.[0] || null,
        };

        return {
            jobCount,
            jobs: jobs.slice(0, 20),
            hiringSignals,
            pageText: text.substring(0, 1000),
        };
    }, slug);

    return {
        company: slug,
        url: jobsUrl,
        ...data,
    };
}

async function getCompanyInsights(page, params) {
    const { company_linkedin_url } = params;
    if (!company_linkedin_url) throw new Error('company_linkedin_url is required');

    const slug = company_linkedin_url.replace(/\/$/, '').split('/').pop();

    // Visit the company about page for headcount and details
    const aboutUrl = `https://www.linkedin.com/company/${slug}/about/`;
    await page.goto(aboutUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    if (page.url().includes('/login')) {
        throw new Error('LinkedIn session expired');
    }

    const data = await page.evaluate(() => {
        const main = document.querySelector('main');
        const text = main ? main.innerText : '';

        // Extract structured details
        const details = {};

        // Company size
        const sizeMatch = text.match(/(\d[\d,]*(?:-\d[\d,]*)?)\s*employees/i);
        if (sizeMatch) details.employeeCount = sizeMatch[1];

        // Industry
        const industryMatch = text.match(/Industry\s*\n\s*([^\n]+)/i);
        if (industryMatch) details.industry = industryMatch[1].trim();

        // Founded
        const foundedMatch = text.match(/Founded\s*\n\s*(\d{4})/i);
        if (foundedMatch) details.founded = foundedMatch[1];

        // Website
        const websiteMatch = text.match(/(?:Website|website)\s*\n\s*(https?:\/\/[^\s\n]+)/i);
        if (websiteMatch) details.website = websiteMatch[1];

        // Headquarters
        const hqMatch = text.match(/Headquarters\s*\n\s*([^\n]+)/i);
        if (hqMatch) details.headquarters = hqMatch[1].trim();

        // Specialties
        const specMatch = text.match(/Specialties\s*\n\s*([^\n]+)/i);
        if (specMatch) details.specialties = specMatch[1].trim();

        // Type (public, private, etc)
        const typeMatch = text.match(/(?:Company type|Type)\s*\n\s*([^\n]+)/i);
        if (typeMatch) details.companyType = typeMatch[1].trim();

        // Recent hires signal
        const hiredMatch = text.match(/(\d+)\s*(?:new\s+)?employees?\s+(?:joined|hired)/i);
        if (hiredMatch) details.recentHires = parseInt(hiredMatch[1]);

        // "X people from your school" = connection signal
        const schoolMatch = text.match(/(\d+)\s+people?\s+from\s+your\s+school/i);
        if (schoolMatch) details.schoolConnections = parseInt(schoolMatch[1]);

        return {
            details,
            pageText: text.substring(0, 1500),
        };
    });

    return {
        company: slug,
        url: aboutUrl,
        ...data,
    };
}

async function getRelatedCompanies(page, params) {
    const { company_linkedin_url } = params;
    if (!company_linkedin_url) throw new Error('company_linkedin_url is required');

    const slug = company_linkedin_url.replace(/\/$/, '').split('/').pop();

    // Visit the company page — similar companies are shown in sidebar
    await page.goto(`https://www.linkedin.com/company/${slug}/`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);

    if (page.url().includes('/login')) {
        throw new Error('LinkedIn session expired');
    }

    const data = await page.evaluate(() => {
        const companies = [];

        // LinkedIn shows "Similar pages" or "Pages people also viewed" in the sidebar
        // Look for the section with company links
        const allLinks = document.querySelectorAll('a[href*="/company/"]');
        const seen = new Set();

        for (const link of allLinks) {
            const href = link.href || '';
            // Skip the current company and non-company links
            if (!href.includes('/company/') || href.includes('/jobs') || href.includes('/people') || href.includes('/about')) continue;

            const slug = href.replace(/\/$/, '').split('/').pop();
            if (seen.has(slug) || slug.length < 2) continue;
            seen.add(slug);

            // Get the nearest text context
            const container = link.closest('li, div[class*="aside"], section') || link.parentElement;
            const text = container ? container.innerText.trim() : link.innerText.trim();
            const lines = text.split('\n').map(l => l.trim()).filter(l => l);

            // Extract name — usually the link text or first meaningful line
            let name = link.innerText.trim().split('\n')[0].trim();
            if (!name || name.length < 2) name = lines[0] || slug;

            // Try to find industry/followers from surrounding text
            let industry = '';
            let followers = '';
            for (const line of lines) {
                if (/followers/i.test(line)) followers = line;
                else if (line !== name && !line.match(/^(Follow|Similar|Pages|People)/)) {
                    if (!industry) industry = line;
                }
            }

            companies.push({
                name,
                slug,
                industry,
                followers,
                linkedinUrl: `https://www.linkedin.com/company/${slug}/`,
            });
        }

        // Filter out navigation links — keep only sidebar/related companies
        // These typically appear after the main company content
        return companies.filter(c =>
            c.name.length > 1 &&
            c.name !== 'LinkedIn' &&
            !c.name.match(/^(Home|About|Posts|Jobs|People|Videos|Events)$/)
        );
    });

    return {
        company: slug,
        related: data,
    };
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
    const action = process.argv[2];
    const paramsRaw = process.argv[3] || '{}';

    if (!action) {
        console.error(JSON.stringify({ error: 'Usage: linkedin-cdp.js <action> [json_params]' }));
        process.exit(1);
    }

    let params;
    try {
        params = JSON.parse(paramsRaw);
    } catch (e) {
        console.error(JSON.stringify({ error: `Invalid JSON params: ${e.message}` }));
        process.exit(1);
    }

    let browser;
    try {
        const conn = await connectBrowser();
        browser = conn.browser;
        const page = conn.page;

        let result;
        switch (action) {
            case 'session_check':
                result = await sessionCheck(page);
                break;
            case 'search_companies':
                result = await searchCompanies(page, params);
                break;
            case 'search_people':
                result = await searchPeople(page, params);
                break;
            case 'get_company_profile':
                result = await getCompanyProfile(page, params);
                break;
            case 'get_person_profile':
                result = await getPersonProfile(page, params);
                break;
            case 'search_employees':
                result = await searchEmployees(page, params);
                break;
            case 'get_company_jobs':
                result = await getCompanyJobs(page, params);
                break;
            case 'get_company_insights':
                result = await getCompanyInsights(page, params);
                break;
            case 'get_related_companies':
                result = await getRelatedCompanies(page, params);
                break;
            default:
                throw new Error(`Unknown action: ${action}`);
        }

        console.log(JSON.stringify(result));
        process.exit(0);
    } catch (e) {
        console.error(JSON.stringify({ error: e.message }));
        process.exit(1);
    }
}

main();
