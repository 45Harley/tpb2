<?php
/**
 * The Record - 0t/record.php
 * Content only - documented lies, illegal actions, court losses
 * No backend processing - that's all in index.php
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Record: Trump 2025 - Lies & Illegal Actions</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Georgia, serif;
            background: #0a0a0a;
            color: #e0e0e0;
            line-height: 1.7;
        }
        
        a {
            color: #6ca0dc;
        }
        
        /* SITE NAV */
        .site-nav {
            display: flex;
            justify-content: center;
            gap: 30px;
            padding: 15px 20px;
            background: #111;
            border-bottom: 1px solid #333;
        }
        
        .site-nav a {
            color: #888;
            text-decoration: none;
            padding: 8px 20px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .site-nav a:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
        }
        
        .site-nav a.active {
            color: #d4af37;
            background: rgba(212, 175, 55, 0.1);
        }
        
        /* HEADER */
        .header {
            background: linear-gradient(135deg, #1a1a2a 0%, #0a0a0a 100%);
            padding: 60px 20px;
            text-align: center;
            border-bottom: 1px solid #333;
        }
        
        .header h1 {
            font-size: 2.8rem;
            margin-bottom: 15px;
            font-weight: normal;
        }
        
        .header h1 span {
            color: #d4af37;
        }
        
        .header .subtitle {
            font-size: 1.3rem;
            color: #888;
            margin-bottom: 30px;
        }
        
        .header .stats {
            display: flex;
            justify-content: center;
            gap: 50px;
            flex-wrap: wrap;
            margin-top: 30px;
        }
        
        .stat-box {
            text-align: center;
        }
        
        .stat-box .number {
            font-size: 3rem;
            color: #d4af37;
            font-weight: bold;
        }
        
        .stat-box .label {
            font-size: 0.9rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        
        .intro {
            max-width: 800px;
            margin: 0 auto;
            padding: 50px 20px;
            text-align: center;
        }
        
        .intro p {
            font-size: 1.2rem;
            color: #aaa;
            margin-bottom: 20px;
        }
        
        .intro .verify {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid #d4af37;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        
        .intro .verify p {
            color: #d4af37;
            font-size: 1.1rem;
            margin: 0;
        }
        
        .nav-tabs {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 20px;
            background: #111;
            border-bottom: 1px solid #333;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .nav-tabs a {
            color: #888;
            text-decoration: none;
            padding: 10px 25px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .nav-tabs a:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
        }
        
        .nav-tabs a.active {
            color: #d4af37;
            background: rgba(212, 175, 55, 0.1);
        }
        
        .section {
            max-width: 900px;
            margin: 0 auto;
            padding: 50px 20px;
        }
        
        .section h2 {
            font-size: 2rem;
            color: #d4af37;
            margin-bottom: 10px;
            font-weight: normal;
            border-bottom: 1px solid #333;
            padding-bottom: 15px;
        }
        
        .section h2 span {
            font-size: 1rem;
            color: #666;
            font-weight: normal;
        }
        
        .section-intro {
            color: #888;
            margin-bottom: 30px;
            font-style: italic;
        }
        
        .category {
            margin-bottom: 40px;
        }
        
        .category h3 {
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 20px;
            padding-left: 15px;
            border-left: 3px solid #d4af37;
        }
        
        .item {
            background: rgba(255,255,255,0.03);
            border: 1px solid #222;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .item:hover {
            border-color: #444;
        }
        
        .item .claim {
            color: #e57373;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        
        .item .claim::before {
            content: "LIE: ";
            color: #a33;
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .item .truth {
            color: #81c784;
            margin-bottom: 10px;
        }
        
        .item .truth::before {
            content: "TRUTH: ";
            color: #4a4;
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .item .source {
            font-size: 0.85rem;
            color: #666;
        }
        
        .item.illegal .claim::before {
            content: "ACTION: ";
            color: #a33;
        }
        
        .item.illegal .truth::before {
            content: "RULING: ";
            color: #4a4;
        }
        
        .item.illegal .claim {
            color: #ffb74d;
        }
        
        .badge {
            display: inline-block;
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 3px;
            margin-left: 10px;
            vertical-align: middle;
        }
        
        .badge.court-ruled {
            background: #4a2;
            color: #fff;
        }
        
        .badge.repeated {
            background: #a33;
            color: #fff;
        }
        
        .badge.contempt {
            background: #d4af37;
            color: #000;
        }
        
        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 40px 0;
        }
        
        .comparison-box {
            padding: 25px;
            border-radius: 8px;
        }
        
        .comparison-box.trump {
            background: rgba(180, 50, 50, 0.1);
            border: 1px solid #633;
        }
        
        .comparison-box.others {
            background: rgba(100, 100, 100, 0.1);
            border: 1px solid #444;
        }
        
        .comparison-box h4 {
            margin-bottom: 15px;
            font-weight: normal;
        }
        
        .comparison-box.trump h4 {
            color: #e57373;
        }
        
        .comparison-box.others h4 {
            color: #aaa;
        }
        
        .comparison-box ul {
            list-style: none;
        }
        
        .comparison-box li {
            padding: 8px 0;
            border-bottom: 1px solid #333;
            color: #999;
        }
        
        .comparison-box li:last-child {
            border-bottom: none;
        }
        
        .comparison-box li strong {
            color: #fff;
        }
        
        .footer {
            text-align: center;
            padding: 50px 20px;
            border-top: 1px solid #333;
            color: #555;
        }
        
        .footer p {
            margin-bottom: 10px;
        }
        
        .add-voice-cta {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid #d4af37;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
            max-width: 600px;
            margin: 40px auto;
        }
        
        .add-voice-cta p {
            color: #ccc;
            margin-bottom: 15px;
        }
        
        .add-voice-cta a {
            display: inline-block;
            background: #d4af37;
            color: #000;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .add-voice-cta a:hover {
            background: #e5c54a;
        }
        
        @media (max-width: 600px) {
            .header h1 { font-size: 2rem; }
            .header .stats { gap: 30px; }
            .stat-box .number { font-size: 2.2rem; }
            .comparison { grid-template-columns: 1fr; }
            .nav-tabs { flex-wrap: wrap; gap: 10px; }
            .nav-tabs a { padding: 8px 15px; font-size: 0.9rem; }
            .site-nav { gap: 15px; }
            .site-nav a { padding: 8px 15px; font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <nav class="site-nav">
        <a href="/">← TPB</a>
        <a href="/0t/">People Power</a>
        <a href="/0t/record.php" class="active">The Record</a>
    </nav>
    
    <header class="header">
        <h1>The <span>Record</span></h1>
        <p class="subtitle">Documented lies and illegal actions — Trump 2025</p>
        
        <div class="stats">
            <div class="stat-box">
                <div class="number">100+</div>
                <div class="label">Documented Lies</div>
            </div>
            <div class="stat-box">
                <div class="number">34</div>
                <div class="label">Felony Convictions</div>
            </div>
            <div class="stat-box">
                <div class="number">186</div>
                <div class="label">Court Losses (2025)</div>
            </div>
            <div class="stat-box">
                <div class="number">530</div>
                <div class="label">Lawsuits Filed</div>
            </div>
        </div>
    </header>
    
    <section class="intro">
        <p>This page documents verifiable, fact-checked false statements and court-ruled illegal actions by the Trump administration in 2025.</p>
        <p>Every claim includes sources. Every ruling is documented. Nothing here requires you to believe anyone — you can verify it yourself.</p>
        
        <div class="verify">
            <p>Truth doesn't need you to believe it. It just needs you to look.</p>
        </div>
    </section>
    
    <nav class="nav-tabs">
        <a href="#lies" class="active">Lies</a>
        <a href="#illegal">Illegal Actions</a>
        <a href="#losses">Court Losses</a>
        <a href="#lawsuits">Lawsuits</a>
        <a href="#sources">Sources</a>
    </nav>
    
    <!-- LIES SECTION -->
    <section class="section" id="lies">
        <h2>Documented Lies <span>— Exposed by fact-checkers</span></h2>
        <p class="section-intro">CNN documented 100 false claims in just Trump's first 100 days. These are repeated lies — debunked again and again, yet still repeated.</p>
        
        <div class="category">
            <h3>Economy & Prices</h3>
            
            <div class="item">
                <p class="claim">"Grocery prices are down." <span class="badge repeated">Repeated 50+ times</span></p>
                <p class="truth">Grocery prices are UP 1.9-2.7% in 2025 according to Consumer Price Index data.</p>
                <p class="source">Source: Bureau of Labor Statistics, CNN Fact Check</p>
            </div>
            
            <div class="item">
                <p class="claim">"Inflation is stopped."</p>
                <p class="truth">Inflation continues at 2.7-3.0% — the same rate as when Trump took office in January 2025.</p>
                <p class="source">Source: Bureau of Labor Statistics</p>
            </div>
            
            <div class="item">
                <p class="claim">"When I took office, inflation was the worst in 48 years, and some would say in the history of our country."</p>
                <p class="truth">Inflation in January 2025 was 3.0%. The all-time record was 23.7% in 1920. The 40-year high of 9.1% occurred in June 2022 — more than two years before Trump returned.</p>
                <p class="source">Source: Federal Reserve historical data</p>
            </div>
            
            <div class="item">
                <p class="claim">"Price of apples doubled under Biden."</p>
                <p class="truth">Apple prices increased 7-8% under Biden — nowhere near 100%.</p>
                <p class="source">Source: Bureau of Labor Statistics</p>
            </div>
            
            <div class="item">
                <p class="claim">Drug prices will be cut "400%, 500%, 600%, 700%, 800%, 900%."</p>
                <p class="truth">Mathematically impossible. A 100% cut would make drugs free. You cannot cut more than 100%.</p>
                <p class="source">Source: Basic math</p>
            </div>
            
            <div class="item">
                <p class="claim">"Gas is under $2.50 in much of the country" and "just hit $1.99 a gallon."</p>
                <p class="truth">Only 4 states had averages below $2.50 (Oklahoma, Arkansas, Iowa, Colorado). National average: $2.90/gallon.</p>
                <p class="source">Source: AAA Gas Prices</p>
            </div>
            
            <div class="item">
                <p class="claim">"We were losing $2 trillion a year on trade."</p>
                <p class="truth">Total US trade deficit in 2024 was $918 billion (goods and services) or $1.2 trillion (goods only). Neither is close to $2 trillion.</p>
                <p class="source">Source: US Census Bureau</p>
            </div>
            
            <div class="item">
                <p class="claim">Tariffs are "paid by foreign countries."</p>
                <p class="truth">Tariffs are paid by US importers, who pass costs to American consumers. Trump contradicted himself when he said he'd lower coffee prices by lowering coffee tariffs.</p>
                <p class="source">Source: Basic trade economics, Trump's own statement</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Immigration</h3>
            
            <div class="item">
                <p class="claim">"Millions" of criminals and terrorists crossing the border.</p>
                <p class="truth">CBP data shows terrorism-related encounters are extremely rare. "Millions of criminals" has no basis in any government data.</p>
                <p class="source">Source: CBP Statistics</p>
            </div>
            
            <div class="item">
                <p class="claim">Countries are "emptying their prisons" to send criminals to US.</p>
                <p class="truth">No evidence any country has done this. Debunked repeatedly.</p>
                <p class="source">Source: FactCheck.org</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Crime</h3>
            
            <div class="item">
                <p class="claim">"Crime is through the roof."</p>
                <p class="truth">FBI data shows violent crime dropped 3% in 2023 and continued declining in 2024. Murder rate dropped 11.6%.</p>
                <p class="source">Source: FBI Uniform Crime Report</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Elections</h3>
            
            <div class="item">
                <p class="claim">"I won the 2020 election." <span class="badge repeated">Repeated constantly</span></p>
                <p class="truth">Biden won 306-232 electoral votes. Trump lost 61 of 62 court cases challenging results. His own Attorney General, election officials, and judges (including Trump appointees) confirmed no widespread fraud.</p>
                <p class="source">Source: Every court, every official, every recount</p>
            </div>
        </div>
    </section>
    
    <!-- ILLEGAL ACTIONS SECTION -->
    <section class="section" id="illegal">
        <h2>Illegal Actions <span>— Blocked or ruled unlawful by courts</span></h2>
        <p class="section-intro">Federal judges — including Trump appointees — have ruled these actions illegal or unconstitutional.</p>
        
        <div class="category">
            <h3>Constitutional Violations</h3>
            
            <div class="item illegal">
                <p class="claim">Birthright citizenship executive order. <span class="badge court-ruled">Blocked by Court</span></p>
                <p class="truth">Federal judge blocked as "blatantly unconstitutional" — the 14th Amendment is clear and has been settled law for 125+ years.</p>
                <p class="source">Source: US District Court</p>
            </div>
            
            <div class="item illegal">
                <p class="claim">Defunding congressionally-appropriated programs.</p>
                <p class="truth">The President cannot refuse to spend money Congress has appropriated. This was established in Nixon-era Impoundment Control Act.</p>
                <p class="source">Source: Impoundment Control Act of 1974</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Contempt of Court</h3>
            
            <div class="item illegal">
                <p class="claim">Deportation of Kilmar Abrego Garcia to El Salvador. <span class="badge contempt">Contempt of Court</span></p>
                <p class="truth">Federal judge ruled the Trump administration in CONTEMPT OF COURT for violating deportation order. Administration was ordered to "facilitate" return of wrongfully deported Maryland man.</p>
                <p class="source">Source: Judge Paula Xinis, D. Maryland</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Immigration Enforcement</h3>
            
            <div class="item illegal">
                <p class="claim">Using Alien Enemies Act for immigration enforcement. <span class="badge court-ruled">5th Circuit Blocked</span></p>
                <p class="truth">The 5th Circuit — one of the most conservative appeals courts — ruled immigration does not constitute an "invasion" under the 1798 wartime law.</p>
                <p class="source">Source: 5th Circuit Court of Appeals, September 2025</p>
            </div>
        </div>
    </section>
    
    <!-- COURT LOSSES SECTION -->
    <section class="section" id="losses">
        <h2>Court Losses <span>— A historic record of defeat</span></h2>
        <p class="section-intro">Trump has lost more court cases than any president in American history.</p>
        
        <div class="category">
            <h3>Criminal Convictions</h3>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">NY Hush Money Case — May 30, 2024</p>
                <p class="truth"><strong>GUILTY on ALL 34 felony counts</strong> of falsifying business records. First former president convicted of felonies in American history.</p>
                <p class="source">Source: Manhattan DA, Judge Juan Merchan</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Civil Judgments</h3>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">E. Jean Carroll Cases</p>
                <p class="truth"><strong>$88.3 million total</strong> in judgments for sexual abuse and defamation.</p>
                <p class="source">Source: US District Court SDNY</p>
            </div>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">NY Civil Fraud Case</p>
                <p class="truth"><strong>FRAUD FINDING UPHELD.</strong> Trump and sons banned from corporate leadership.</p>
                <p class="source">Source: NY Appellate Division (August 2025)</p>
            </div>
        </div>
        
        <div class="category">
            <h3>The Numbers</h3>
            
            <div class="comparison">
                <div class="comparison-box trump">
                    <h4>Trump Court Losses</h4>
                    <ul>
                        <li><strong>34 felony convictions</strong></li>
                        <li><strong>$88+ million</strong> civil judgments</li>
                        <li><strong>61 of 62</strong> election lawsuits lost</li>
                        <li><strong>186</strong> second term cases lost</li>
                        <li><strong>225+ judges</strong> ruled detention policy illegal</li>
                    </ul>
                </div>
                <div class="comparison-box others">
                    <h4>Normal Win Rates</h4>
                    <ul>
                        <li>Past administrations: <strong>~70%</strong></li>
                        <li>Trump first term: <strong>17%</strong></li>
                        <li>Trump second term: <strong>~38%</strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
    
    <!-- LAWSUITS -->
    <section class="section" id="lawsuits">
        <h2>Unprecedented Lawsuits</h2>
        
        <div class="comparison">
            <div class="comparison-box trump">
                <h4>Trump 2025</h4>
                <ul>
                    <li><strong>530 lawsuits</strong> in first 10 months</li>
                    <li><strong>253 active cases</strong> pending</li>
                    <li><strong>20-30 cases</strong> to Supreme Court</li>
                </ul>
            </div>
            <div class="comparison-box others">
                <h4>Previous Presidents</h4>
                <ul>
                    <li>Biden: <strong>133</strong> (entire term)</li>
                    <li>Obama: <strong>30-40</strong></li>
                    <li>Bush: <strong>&lt;20</strong></li>
                </ul>
            </div>
        </div>
    </section>
    
    <!-- SOURCES -->
    <section class="section" id="sources">
        <h2>Sources</h2>
        
        <div class="category">
            <h3>Fact-Checkers</h3>
            <div class="item">
                <p><a href="https://www.cnn.com/politics/fact-check-trump-false-claims-debunked" target="_blank">CNN Fact Check</a></p>
                <p><a href="https://www.factcheck.org/person/donald-trump/" target="_blank">FactCheck.org</a></p>
            </div>
        </div>
        
        <div class="category">
            <h3>Legal Trackers</h3>
            <div class="item">
                <p><a href="https://www.justsecurity.org/107087/tracker-litigation-legal-challenges-trump-administration/" target="_blank">Just Security Litigation Tracker</a></p>
                <p><a href="https://www.lawfaremedia.org/projects-series/trials-of-the-trump-administration/tracking-trump-administration-litigation" target="_blank">Lawfare Tracker</a></p>
            </div>
        </div>
    </section>
    
    <div class="add-voice-cta">
        <p>Convinced? Add your voice to the opposition.</p>
        <a href="/0t/">Join People Power →</a>
    </div>
    
    <footer class="footer">
        <p>Last updated: December 2025</p>
        <p>This page contains no opinions — only documented facts and court rulings.</p>
        <p>Truth is self-evident. Provable. Infinite. It only needs to be revealed.</p>
    </footer>
    
    <script>
        document.querySelectorAll('.nav-tabs a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({ behavior: 'smooth' });
                document.querySelectorAll('.nav-tabs a').forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
