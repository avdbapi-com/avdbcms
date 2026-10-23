<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xhtml="http://www.w3.org/1999/xhtml"
    xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
    exclude-result-prefixes="s xhtml video">
    <xsl:output method="html" encoding="UTF-8" indent="yes"/>

    <xsl:template match="/">
        <html lang="en">
            <head>
                <meta charset="UTF-8"/>
                <meta name="viewport" content="width=device-width, initial-scale=1"/>
                <title>AVDBCMS Sitemap</title>
                <style>
                    :root {
                        color-scheme: dark;
                        --bg: #090b11;
                        --panel: #121621;
                        --panel-soft: #171c29;
                        --border: #2a3142;
                        --text: #f4f6fb;
                        --muted: #aab2c5;
                        --accent: #ff4d7d;
                        --accent-soft: rgba(255, 77, 125, .16);
                        --link: #7dd3fc;
                    }
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        background: var(--bg);
                        color: var(--text);
                        font: 14px/1.5 Arial, Helvetica, sans-serif;
                    }
                    a { color: var(--link); text-decoration: none; }
                    a:hover { text-decoration: underline; }
                    .shell {
                        width: min(1280px, calc(100% - 32px));
                        margin: 0 auto;
                        padding: 32px 0 48px;
                    }
                    .hero {
                        border: 1px solid var(--border);
                        background: linear-gradient(135deg, #171c29 0%, #10141f 56%, #1a101b 100%);
                        border-radius: 8px;
                        padding: 24px;
                        margin-bottom: 18px;
                    }
                    .eyebrow {
                        color: var(--accent);
                        font-weight: 700;
                        letter-spacing: .08em;
                        text-transform: uppercase;
                        font-size: 12px;
                    }
                    h1 {
                        margin: 8px 0 10px;
                        font-size: clamp(28px, 4vw, 46px);
                        line-height: 1.05;
                        letter-spacing: 0;
                    }
                    .summary {
                        margin: 0;
                        color: var(--muted);
                        max-width: 860px;
                    }
                    .stats {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                        gap: 12px;
                        margin: 18px 0;
                    }
                    .stat {
                        border: 1px solid var(--border);
                        border-radius: 8px;
                        background: var(--panel);
                        padding: 14px 16px;
                    }
                    .stat strong {
                        display: block;
                        font-size: 24px;
                        line-height: 1;
                    }
                    .stat span {
                        color: var(--muted);
                        font-size: 12px;
                        text-transform: uppercase;
                        letter-spacing: .06em;
                    }
                    .table-wrap {
                        border: 1px solid var(--border);
                        border-radius: 8px;
                        overflow: auto;
                        background: var(--panel);
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        min-width: 900px;
                    }
                    th, td {
                        padding: 12px 14px;
                        border-bottom: 1px solid var(--border);
                        text-align: left;
                        vertical-align: top;
                    }
                    th {
                        position: sticky;
                        top: 0;
                        z-index: 1;
                        background: #181d2a;
                        color: var(--muted);
                        font-size: 12px;
                        text-transform: uppercase;
                        letter-spacing: .06em;
                    }
                    tr:hover td { background: rgba(255, 255, 255, .025); }
                    .url {
                        display: block;
                        max-width: 520px;
                        overflow-wrap: anywhere;
                        font-weight: 700;
                    }
                    .badge {
                        display: inline-flex;
                        align-items: center;
                        border: 1px solid rgba(255, 77, 125, .35);
                        background: var(--accent-soft);
                        color: #ffd8e3;
                        border-radius: 999px;
                        padding: 3px 9px;
                        font-size: 12px;
                        font-weight: 700;
                        white-space: nowrap;
                    }
                    .muted { color: var(--muted); }
                    .desc {
                        color: var(--muted);
                        max-width: 520px;
                    }
                    .footer {
                        color: var(--muted);
                        font-size: 12px;
                        margin-top: 14px;
                    }
                    @media (max-width: 760px) {
                        .shell { width: min(100% - 20px, 1280px); padding-top: 18px; }
                        .hero { padding: 18px; }
                        table { min-width: 780px; }
                    }
                </style>
            </head>
            <body>
                <main class="shell">
                    <section class="hero">
                        <div class="eyebrow">AVDBCMS SEO</div>
                        <xsl:choose>
                            <xsl:when test="s:sitemapindex">
                                <h1>Sitemap Index</h1>
                                <p class="summary">This index lists every language sitemap file that crawlers can discover directly.</p>
                            </xsl:when>
                            <xsl:otherwise>
                                <h1>URL Sitemap</h1>
                                <p class="summary">This sitemap lists canonical URLs, localized alternates, and video metadata in crawler-readable XML.</p>
                            </xsl:otherwise>
                        </xsl:choose>
                    </section>

                    <xsl:choose>
                        <xsl:when test="s:sitemapindex">
                            <xsl:call-template name="sitemap-index"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:call-template name="urlset"/>
                        </xsl:otherwise>
                    </xsl:choose>

                    <p class="footer">Styled preview only. Search engines still receive the original XML sitemap content.</p>
                </main>
            </body>
        </html>
    </xsl:template>

    <xsl:template name="sitemap-index">
        <section class="stats">
            <div class="stat">
                <strong><xsl:value-of select="count(s:sitemapindex/s:sitemap)"/></strong>
                <span>Sitemap files</span>
            </div>
            <div class="stat">
                <strong><xsl:value-of select="count(s:sitemapindex/s:sitemap[contains(s:loc, 'sitemap-pages-')])"/></strong>
                <span>Page files</span>
            </div>
            <div class="stat">
                <strong><xsl:value-of select="count(s:sitemapindex/s:sitemap[contains(s:loc, 'sitemap-videos-')])"/></strong>
                <span>Video files</span>
            </div>
        </section>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sitemap</th>
                        <th>Type</th>
                        <th>Last Modified</th>
                    </tr>
                </thead>
                <tbody>
                    <xsl:for-each select="s:sitemapindex/s:sitemap">
                        <tr>
                            <td><a class="url" href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                            <td><span class="badge"><xsl:call-template name="sitemap-type"/></span></td>
                            <td class="muted"><xsl:value-of select="s:lastmod"/></td>
                        </tr>
                    </xsl:for-each>
                </tbody>
            </table>
        </div>
    </xsl:template>

    <xsl:template name="urlset">
        <section class="stats">
            <div class="stat">
                <strong><xsl:value-of select="count(s:urlset/s:url)"/></strong>
                <span>URLs</span>
            </div>
            <div class="stat">
                <strong><xsl:value-of select="count(s:urlset/s:url/video:video)"/></strong>
                <span>Video entries</span>
            </div>
            <div class="stat">
                <strong><xsl:value-of select="count(s:urlset/s:url/xhtml:link)"/></strong>
                <span>Hreflang links</span>
            </div>
        </section>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>URL</th>
                        <th>Last Modified</th>
                        <th>Alternates</th>
                        <th>Video</th>
                    </tr>
                </thead>
                <tbody>
                    <xsl:for-each select="s:urlset/s:url">
                        <tr>
                            <td><a class="url" href="{s:loc}"><xsl:value-of select="s:loc"/></a></td>
                            <td class="muted"><xsl:value-of select="s:lastmod"/></td>
                            <td>
                                <xsl:choose>
                                    <xsl:when test="xhtml:link">
                                        <span class="badge"><xsl:value-of select="count(xhtml:link)"/> langs</span>
                                    </xsl:when>
                                    <xsl:otherwise><span class="muted">None</span></xsl:otherwise>
                                </xsl:choose>
                            </td>
                            <td>
                                <xsl:choose>
                                    <xsl:when test="video:video">
                                        <strong><xsl:value-of select="video:video/video:title"/></strong>
                                        <div class="desc">
                                            <xsl:value-of select="substring(video:video/video:description, 1, 180)"/>
                                            <xsl:if test="string-length(video:video/video:description) &gt; 180">...</xsl:if>
                                        </div>
                                    </xsl:when>
                                    <xsl:otherwise><span class="muted">Page URL</span></xsl:otherwise>
                                </xsl:choose>
                            </td>
                        </tr>
                    </xsl:for-each>
                </tbody>
            </table>
        </div>
    </xsl:template>

    <xsl:template name="sitemap-type">
        <xsl:choose>
            <xsl:when test="contains(s:loc, 'sitemap-pages-')">Pages</xsl:when>
            <xsl:when test="contains(s:loc, 'sitemap-videos-')">Videos</xsl:when>
            <xsl:otherwise>Sitemap</xsl:otherwise>
        </xsl:choose>
    </xsl:template>
</xsl:stylesheet>
