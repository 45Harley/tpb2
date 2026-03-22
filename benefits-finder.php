<?php
/**
 * My Benefits Finder — Civic Profile for program matching
 * ========================================================
 * 35 fields across 7 sections + benefits match opt-in.
 * Saves to user_profile table via api/benefits-profile.php.
 */

$config = require 'config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
if (!$dbUser) { header('Location: /login.php'); exit; }
$userId = (int)$dbUser['user_id'];

$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'profile';

// Load existing profile
$stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: [];

// Helper: get saved value
function pv($key) { global $profile; return $profile[$key] ?? ''; }
function sel($key, $val) { return pv($key) === $val ? ' selected' : ''; }
function chk($key) { return !empty($profile[$key]) ? ' checked' : ''; }

// Count filled
$allFields = ['us_citizen','citizenship_type','gender','date_of_birth','living_situation',
    'voter_registered','party_affiliation','voting_frequency','primary_voter','first_time_voter',
    'education_level','employment_status','student_status','industry','household_income_range','student_debt','public_service_employer',
    'health_insurance','veteran','veteran_branch',
    'household_size','children_under_18','caregiver',
    'marital_status','monthly_housing_cost','savings_range','current_benefits','immigration_status','preferred_language','pays_utilities',
    'has_disability','disability_type','pregnant','single_parent','criminal_record','domestic_violence',
    'benefits_match_optin'];
$filled = 0;
foreach ($allFields as $f) { if (isset($profile[$f]) && $profile[$f] !== null && $profile[$f] !== '') $filled++; }
$total = count($allFields);
$pct = $total > 0 ? round(($filled / $total) * 100) : 0;

$pageTitle = 'My Benefits Finder | The People\'s Branch';
$ogTitle = 'My Benefits Finder — The People\'s Branch';
$ogDescription = 'Help us match you to programs and services you may qualify for.';

$pageStyles = <<<'CSS'
.bf { max-width: 600px; margin: 0 auto; padding: 2rem 1rem; }
.bf h1 { font-size: 1.8rem; }
.bf .subtitle { color: #b0b0b0; margin-bottom: 1.5rem; font-size: 0.95rem; }
.bf .card h2 { font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem; }
.bf .section-pts {
    margin-left: auto; font-size: 0.75rem; color: #d4af37;
    background: rgba(212,175,55,0.1); border: 1px solid rgba(212,175,55,0.3);
    padding: 2px 8px; border-radius: 10px; font-weight: 400;
}
.bf .form-group { margin-bottom: 1rem; }
.bf .form-group label {
    display: flex; align-items: center; gap: 0.4rem;
    color: #ccc; font-size: 0.9rem; margin-bottom: 0.4rem; font-weight: 500;
    position: relative; cursor: help;
}
.bf .form-group label .pts {
    font-size: 0.7rem; color: #d4af37; background: rgba(212,175,55,0.1);
    padding: 1px 6px; border-radius: 8px;
}
.bf .form-group label .info-icon {
    font-size: 0.75rem; color: #666; margin-left: auto; transition: color 0.2s;
}
.bf .form-group label:hover .info-icon { color: #d4af37; }
.bf .form-group label .tooltip {
    display: none; position: absolute; top: 100%; left: 0; right: 0;
    background: #0a0a0f; border: 1px solid #d4af37; color: #ccc;
    font-size: 0.78rem; font-weight: 400; font-style: italic;
    padding: 0.5rem 0.75rem; border-radius: 6px; z-index: 10;
    margin-top: 2px; line-height: 1.4; box-shadow: 0 4px 12px rgba(0,0,0,0.5);
}
.bf .form-group label:hover .tooltip { display: block; }
.bf .hover-reason {
    font-size: 0.78rem; color: #888; margin-bottom: 0.4rem;
    font-style: italic; padding-left: 0.1rem;
}
.bf select, .bf input[type="text"], .bf input[type="date"], .bf input[type="number"] {
    width: 100%; padding: 0.65rem 0.75rem; background: #0a0a0f;
    border: 1px solid #333; color: #e0e0e0; border-radius: 6px;
    font-size: 0.9rem; transition: border-color 0.2s;
}
.bf select:focus, .bf input:focus { outline: none; border-color: #d4af37; }
.bf select { cursor: pointer; }
.bf select option { background: #0a0a0f; color: #e0e0e0; }
.bf .form-row { display: flex; gap: 0.75rem; }
.bf .form-row .form-group { flex: 1; }
.bf .toggle-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 0; border-bottom: 1px solid rgba(255,255,255,0.05);
}
.bf .toggle-row:last-child { border-bottom: none; }
.bf .toggle-label { display: flex; flex-direction: column; gap: 0.15rem; }
.bf .toggle-label .name { color: #ccc; font-size: 0.9rem; font-weight: 500; }
.bf .toggle-label .reason { color: #888; font-size: 0.75rem; font-style: italic; }
.bf .toggle-label .pts-inline { font-size: 0.7rem; color: #d4af37; }
.bf .toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.bf .toggle-switch input { opacity: 0; width: 0; height: 0; }
.bf .toggle-switch .slider {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: #333; border-radius: 24px; cursor: pointer; transition: background 0.3s;
}
.bf .toggle-switch .slider::before {
    content: ''; position: absolute; width: 18px; height: 18px; left: 3px; bottom: 3px;
    background: #888; border-radius: 50%; transition: transform 0.3s, background 0.3s;
}
.bf .toggle-switch input:checked + .slider { background: rgba(212,175,55,0.3); }
.bf .toggle-switch input:checked + .slider::before { transform: translateX(20px); background: #d4af37; }
.bf .progress-bar { background: #0a0a0f; border-radius: 10px; height: 8px; margin: 1rem 0 0.5rem; overflow: hidden; }
.bf .progress-bar .fill { height: 100%; background: linear-gradient(90deg, #d4af37, #e4cf67); border-radius: 10px; transition: width 0.5s ease; }
.bf .progress-text { display: flex; justify-content: space-between; font-size: 0.78rem; color: #888; }
.bf .save-btn {
    width: 100%; padding: 0.85rem; background: #d4af37; color: #000; border: none;
    border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer;
    margin-top: 0.5rem; transition: background 0.2s;
}
.bf .save-btn:hover { background: #e4bf47; }
.bf .save-btn.saved { background: #2ecc71; }
.bf .privacy-note { text-align: center; font-size: 0.75rem; color: #666; margin-top: 1rem; line-height: 1.5; }
.bf .save-status { text-align: center; font-size: 0.85rem; color: #2ecc71; margin-top: 0.5rem; min-height: 1.2em; }
@media (max-width: 500px) { .bf .form-row { flex-direction: column; gap: 0; } }
CSS;

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>

<main class="bf">
    <h1>My Benefits Finder</h1>
    <p class="subtitle">Help us match you to programs and services you may qualify for.</p>

    <div class="card" style="padding: 1rem 1.5rem;">
        <div class="progress-bar"><div class="fill" id="progressFill" style="width: <?= $pct ?>%;"></div></div>
        <div class="progress-text">
            <span id="progressCount"><?= $filled ?> of <?= $total ?> fields completed</span>
            <span id="progressPts">+<?= $filled * 5 ?> of <?= $total * 5 ?> civic points</span>
        </div>
    </div>

    <form id="benefitsForm">

    <!-- Identity -->
    <div class="card">
        <h2>Identity <span class="section-pts">+25 pts</span></h2>

        <div class="form-group">
            <label>U.S. Citizen <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Determines which programs and rights apply to you</span></label>
            <div class="hover-reason">Determines which programs and rights apply to you</div>
            <select name="us_citizen">
                <option value="">— Select —</option>
                <option value="yes"<?= sel('us_citizen','yes') ?>>Yes</option>
                <option value="no"<?= sel('us_citizen','no') ?>>No</option>
                <option value="pending"<?= sel('us_citizen','pending') ?>>Pending</option>
            </select>
        </div>

        <div class="form-group">
            <label>Citizenship Type <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Helps connect you with immigration services if needed</span></label>
            <div class="hover-reason">Helps connect you with immigration services if needed</div>
            <select name="citizenship_type">
                <option value="">— Select —</option>
                <option value="born"<?= sel('citizenship_type','born') ?>>Born citizen</option>
                <option value="naturalized"<?= sel('citizenship_type','naturalized') ?>>Naturalized</option>
            </select>
        </div>

        <div class="form-group">
            <label>Gender <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Some programs are gender-specific — we'll surface the right ones</span></label>
            <div class="hover-reason">Some programs are gender-specific — we'll surface the right ones</div>
            <select name="gender">
                <option value="">— Select —</option>
                <option value="male"<?= sel('gender','male') ?>>Male</option>
                <option value="female"<?= sel('gender','female') ?>>Female</option>
                <option value="other"<?= sel('gender','other') ?>>Other</option>
                <option value="prefer_not_to_say"<?= sel('gender','prefer_not_to_say') ?>>Prefer not to say</option>
            </select>
        </div>

        <div class="form-group">
            <label>Date of Birth <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Your age determines eligibility for Medicare, Social Security, youth services</span></label>
            <div class="hover-reason">Your age determines eligibility for Medicare, Social Security, youth services</div>
            <input type="date" name="date_of_birth" value="<?= htmlspecialchars(pv('date_of_birth')) ?>">
        </div>

        <div class="form-group">
            <label>Living Situation <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Connects you to housing programs, tax benefits, and rental assistance</span></label>
            <div class="hover-reason">Connects you to housing programs, tax benefits, and rental assistance</div>
            <select name="living_situation">
                <option value="">— Select —</option>
                <option value="own"<?= sel('living_situation','own') ?>>Own</option>
                <option value="rent"<?= sel('living_situation','rent') ?>>Rent</option>
                <option value="with_family"<?= sel('living_situation','with_family') ?>>Live with family</option>
                <option value="group"<?= sel('living_situation','group') ?>>Group / shared housing</option>
                <option value="homeless"<?= sel('living_situation','homeless') ?>>Homeless / housing insecure</option>
            </select>
        </div>
    </div>

    <!-- Voter Status -->
    <div class="card">
        <h2>Voter Status <span class="section-pts">+25 pts</span></h2>

        <div class="form-group">
            <label>Registered to Vote <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Registered voters get priority attention from reps — we can help you register</span></label>
            <div class="hover-reason">Registered voters get priority attention from reps — we can help you register</div>
            <select name="voter_registered">
                <option value="">— Select —</option>
                <option value="yes"<?= sel('voter_registered','yes') ?>>Yes</option>
                <option value="no"<?= sel('voter_registered','no') ?>>No</option>
                <option value="unsure"<?= sel('voter_registered','unsure') ?>>Unsure</option>
            </select>
        </div>

        <div class="form-group">
            <label>Party Affiliation <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Helps match you to primary elections and party-specific resources</span></label>
            <div class="hover-reason">Helps match you to primary elections and party-specific resources</div>
            <select name="party_affiliation">
                <option value="">— Select —</option>
                <?php foreach (['Democratic','Republican','Independent','Green','Libertarian','Other','None'] as $p): ?>
                <option value="<?= $p ?>"<?= pv('party_affiliation') === $p ? ' selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>How Often Do You Vote? <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Reps pay more attention to frequent voters — this helps us advocate for you</span></label>
            <div class="hover-reason">Reps pay more attention to frequent voters — this helps us advocate for you</div>
            <select name="voting_frequency">
                <option value="">— Select —</option>
                <option value="every"<?= sel('voting_frequency','every') ?>>Every election</option>
                <option value="most"<?= sel('voting_frequency','most') ?>>Most elections</option>
                <option value="sometimes"<?= sel('voting_frequency','sometimes') ?>>Sometimes</option>
                <option value="rarely"<?= sel('voting_frequency','rarely') ?>>Rarely</option>
                <option value="never"<?= sel('voting_frequency','never') ?>>Never</option>
            </select>
        </div>

        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I vote in primaries</span><span class="reason">Primary voters choose who's on the ballot — reps notice</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="primary_voter"<?= chk('primary_voter') ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div class="toggle-label"><span class="name">First-time voter</span><span class="reason">We'll walk you through the whole process — registration to election day</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="first_time_voter"<?= chk('first_time_voter') ?>><span class="slider"></span></label>
        </div>
    </div>

    <!-- Work & Education -->
    <div class="card">
        <h2>Work &amp; Education <span class="section-pts">+35 pts</span></h2>

        <div class="form-group">
            <label>Education Level <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Determines eligibility for Pell grants, job training, GED programs, and career resources</span></label>
            <div class="hover-reason">Determines eligibility for Pell grants, job training, GED programs, and career resources</div>
            <select name="education_level">
                <option value="">— Select —</option>
                <?php foreach (['No high school diploma','GED','High school diploma','Some college','Trade / vocational','Associates','Bachelors','Masters','Doctorate'] as $e): ?>
                <option value="<?= $e ?>"<?= pv('education_level') === $e ? ' selected' : '' ?>><?= $e ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Employment Status <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Matches you to job training, retirement benefits, or disability services</span></label>
            <div class="hover-reason">Matches you to job training, retirement benefits, or disability services</div>
            <select name="employment_status">
                <option value="">— Select —</option>
                <?php foreach (['Employed full-time','Employed part-time','Self-employed','Retired','Student','Unemployed - looking','Unemployed - not looking','Unable to work'] as $e): ?>
                <option value="<?= $e ?>"<?= pv('employment_status') === $e ? ' selected' : '' ?>><?= $e ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Student Status <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Unlocks student loan help, grants, and education resources</span></label>
            <div class="hover-reason">Unlocks student loan help, grants, and education resources</div>
            <select name="student_status">
                <option value="">— Select —</option>
                <option value="full_time"<?= sel('student_status','full_time') ?>>Full-time student</option>
                <option value="part_time"<?= sel('student_status','part_time') ?>>Part-time student</option>
                <option value="not_student"<?= sel('student_status','not_student') ?>>Not a student</option>
            </select>
        </div>

        <div class="form-group">
            <label>Industry / Field <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Helps surface legislation and trade policies that affect your work</span></label>
            <div class="hover-reason">Helps surface legislation and trade policies that affect your work</div>
            <input type="text" name="industry" value="<?= htmlspecialchars(pv('industry')) ?>" placeholder="e.g. Healthcare, Construction, Education, Tech...">
        </div>

        <div class="form-group">
            <label>Household Income Range <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Many programs have income thresholds — helps find what you qualify for</span></label>
            <div class="hover-reason">Many programs have income thresholds — helps find what you qualify for</div>
            <select name="household_income_range">
                <option value="">— Select —</option>
                <?php foreach (['Under $25,000','$25,000-$50,000','$50,000-$75,000','$75,000-$100,000','$100,000-$150,000','$150,000+','Prefer not to say'] as $r): ?>
                <option value="<?= $r ?>"<?= pv('household_income_range') === $r ? ' selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I have student loan debt</span><span class="reason">Connects you to loan forgiveness (PSLF), income-based repayment, and refinancing programs</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="student_debt"<?= chk('student_debt') ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I work for government or nonprofit</span><span class="reason">Public Service Loan Forgiveness (PSLF) can erase your student debt after 10 years</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="public_service_employer"<?= chk('public_service_employer') ?>><span class="slider"></span></label>
        </div>
    </div>

    <!-- Health & Service -->
    <div class="card">
        <h2>Health &amp; Service <span class="section-pts">+15 pts</span></h2>

        <div class="form-group">
            <label>Health Insurance <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Identifies coverage gaps and connects you to affordable options</span></label>
            <div class="hover-reason">Identifies coverage gaps and connects you to affordable options</div>
            <select name="health_insurance">
                <option value="">— Select —</option>
                <?php foreach (['Employer-provided','ACA Marketplace','Medicare','Medicaid','VA / TRICARE','Private','Uninsured','Other'] as $h): ?>
                <option value="<?= $h ?>"<?= pv('health_insurance') === $h ? ' selected' : '' ?>><?= $h ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="toggle-row">
            <div class="toggle-label"><span class="name">U.S. Military Veteran</span><span class="reason">Veterans earn benefits most never claim — we'll help you find yours</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="veteran" id="veteranToggle"<?= chk('veteran') ?>><span class="slider"></span></label>
        </div>

        <div class="form-group" id="branchGroup" style="display: <?= pv('veteran') ? 'block' : 'none' ?>; margin-top: 0.75rem;">
            <label>Branch of Service <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Branch-specific programs exist for each service branch</span></label>
            <div class="hover-reason">Branch-specific programs exist for each service branch</div>
            <select name="veteran_branch">
                <option value="">— Select —</option>
                <?php foreach (['Army','Navy','Air Force','Marines','Coast Guard','Space Force','National Guard'] as $b): ?>
                <option value="<?= $b ?>"<?= pv('veteran_branch') === $b ? ' selected' : '' ?>><?= $b ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Household -->
    <div class="card">
        <h2>Household <span class="section-pts">+15 pts</span></h2>
        <div class="form-row">
            <div class="form-group">
                <label>Household Size <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                    <span class="tooltip">Affects eligibility for SNAP, Medicaid, housing, tax credits</span></label>
                <div class="hover-reason">Affects eligibility for SNAP, Medicaid, housing, tax credits</div>
                <input type="number" name="household_size" min="1" max="20" value="<?= htmlspecialchars(pv('household_size')) ?>" placeholder="e.g. 3">
            </div>
            <div class="form-group">
                <label>Children Under 18 <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                    <span class="tooltip">Unlocks childcare credits, school programs, CHIP, family services</span></label>
                <div class="hover-reason">Unlocks childcare credits, school programs, CHIP, family services</div>
                <input type="number" name="children_under_18" min="0" max="20" value="<?= htmlspecialchars(pv('children_under_18')) ?>" placeholder="e.g. 2">
            </div>
        </div>
        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I'm a caregiver</span><span class="reason">Caregivers qualify for respite programs, tax deductions, support services</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="caregiver"<?= chk('caregiver') ?>><span class="slider"></span></label>
        </div>
    </div>

    <!-- Benefits Eligibility -->
    <div class="card">
        <h2>Benefits Eligibility <span class="section-pts">+35 pts</span></h2>

        <div class="form-group">
            <label>Marital Status <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Affects tax filing status, household composition, and program eligibility thresholds</span></label>
            <div class="hover-reason">Affects tax filing status, household composition, and program eligibility thresholds</div>
            <select name="marital_status">
                <option value="">— Select —</option>
                <?php foreach (['Single','Married','Divorced','Separated','Widowed','Domestic partnership'] as $m): ?>
                <option value="<?= $m ?>"<?= pv('marital_status') === $m ? ' selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Monthly Housing Cost <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Determines eligibility for Section 8, SNAP shelter deduction, and LIHEAP heating assistance</span></label>
            <div class="hover-reason">Determines eligibility for Section 8, SNAP shelter deduction, and LIHEAP heating assistance</div>
            <select name="monthly_housing_cost">
                <option value="">— Select —</option>
                <?php foreach (['$0','Under $500','$500-$1,000','$1,000-$1,500','$1,500-$2,000','$2,000-$3,000','$3,000+'] as $h): ?>
                <option value="<?= $h ?>"<?= pv('monthly_housing_cost') === $h ? ' selected' : '' ?>><?= $h ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Savings / Assets Range <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">SNAP limits assets to $3,000 ($4,500 if elderly/disabled) — CT waives this for most households</span></label>
            <div class="hover-reason">SNAP limits assets to $3,000 ($4,500 if elderly/disabled) — CT waives this for most households</div>
            <select name="savings_range">
                <option value="">— Select —</option>
                <?php foreach (['Under $1,000','$1,000-$3,000','$3,000-$10,000','$10,000-$50,000','$50,000+','Prefer not to say'] as $s): ?>
                <option value="<?= $s ?>"<?= pv('savings_range') === $s ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Immigration Status <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Non-citizen eligibility varies by program — some require 5-year residency, others don't</span></label>
            <div class="hover-reason">Non-citizen eligibility varies by program — some require 5-year residency, others don't</div>
            <select name="immigration_status">
                <option value="">— Select —</option>
                <?php foreach (['U.S. citizen','Permanent resident','Refugee / asylee','Work visa','Student visa','DACA','TPS','Undocumented','Prefer not to say'] as $i): ?>
                <option value="<?= $i ?>"<?= pv('immigration_status') === $i ? ' selected' : '' ?>><?= $i ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Preferred Language <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">All federal programs offer language access — we'll connect you to services in your language</span></label>
            <div class="hover-reason">All federal programs offer language access — we'll connect you to services in your language</div>
            <select name="preferred_language">
                <option value="">— Select —</option>
                <?php foreach (['English','Spanish','Portuguese','Chinese (Mandarin)','Chinese (Cantonese)','French / Haitian Creole','Arabic','Polish','Korean','Vietnamese','Other'] as $l): ?>
                <option value="<?= $l ?>"<?= pv('preferred_language') === $l ? ' selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I pay utility bills (electric, gas, heating)</span><span class="reason">Qualifies you for LIHEAP energy assistance and SNAP utility allowance deductions</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="pays_utilities"<?= chk('pays_utilities') ?>><span class="slider"></span></label>
        </div>
    </div>

    <!-- Personal Circumstances -->
    <div class="card">
        <h2>Personal Circumstances <span class="section-pts">+25 pts</span></h2>

        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I have a disability</span><span class="reason">Opens SSI, SSDI, Medicaid, SNAP special rules, housing priority, and workplace accommodations</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="has_disability" id="disabilityToggle"<?= chk('has_disability') ?>><span class="slider"></span></label>
        </div>

        <div class="form-group" id="disabilityType" style="display: <?= pv('has_disability') ? 'block' : 'none' ?>; margin-top: 0.75rem;">
            <label>Disability Type <span class="pts">+5</span> <span class="info-icon">&#9432;</span>
                <span class="tooltip">Different disability types qualify for different programs and accommodations</span></label>
            <div class="hover-reason">Different disability types qualify for different programs and accommodations</div>
            <select name="disability_type">
                <option value="">— Select —</option>
                <?php foreach (['Physical','Visual','Hearing','Cognitive / intellectual','Mental health','Chronic illness','Multiple','Prefer not to specify'] as $d): ?>
                <option value="<?= $d ?>"<?= pv('disability_type') === $d ? ' selected' : '' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I am pregnant or recently gave birth</span><span class="reason">Immediately expands eligibility for Medicaid, WIC, CHIP, TANF, and prenatal services</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="pregnant"<?= chk('pregnant') ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I am a single parent</span><span class="reason">Qualifies for additional childcare credits, TANF, and head-of-household tax status</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="single_parent"<?= chk('single_parent') ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I have a criminal record</span><span class="reason">Reentry programs, job training, and expungement services can help — some benefit restrictions may apply</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="criminal_record"<?= chk('criminal_record') ?>><span class="slider"></span></label>
        </div>
        <div class="toggle-row">
            <div class="toggle-label"><span class="name">I experienced domestic violence</span><span class="reason">Priority access to emergency housing, legal aid, TANF exemptions, and safety planning</span><span class="pts-inline">+5 pts</span></div>
            <label class="toggle-switch"><input type="checkbox" name="domestic_violence"<?= chk('domestic_violence') ?>><span class="slider"></span></label>
        </div>
    </div>

    <!-- Benefits Match -->
    <div class="card" style="border: 1px solid #d4af37; background: linear-gradient(180deg, #1a1a2e 0%, #1a2a1a 100%);">
        <h2 style="color: #d4af37;">Would you like to see benefits you may qualify for?</h2>
        <p style="color: #ccc; font-size: 0.9rem; margin-bottom: 1rem; line-height: 1.6;">
            Based on your answers above, we can match you to federal and state programs you may be eligible for —
            housing assistance, food benefits, healthcare, tax credits, energy assistance, education grants, and more.
        </p>
        <p style="color: #888; font-size: 0.8rem; margin-bottom: 1.25rem; line-height: 1.5;">
            We don't apply on your behalf. We show you what's available, explain the requirements in plain language,
            and link you directly to the application. Your data stays private — it never leaves this platform.
        </p>
        <div class="toggle-row" style="border: none; padding: 0.75rem; background: rgba(212,175,55,0.05); border-radius: 8px;">
            <div class="toggle-label">
                <span class="name" style="color: #e0e0e0; font-size: 1rem;">Yes — show me what I may qualify for</span>
                <span class="reason">We'll scan your profile against 50+ federal and state programs</span>
                <span class="pts-inline">+25 pts</span>
            </div>
            <label class="toggle-switch"><input type="checkbox" name="benefits_match_optin"<?= chk('benefits_match_optin') ?>><span class="slider"></span></label>
        </div>
    </div>

    <button type="submit" class="save-btn" id="saveBtn">Save Profile</button>
    <div class="save-status" id="saveStatus"></div>

    </form>

    <p class="privacy-note">
        Your information is private and never shared without your consent.<br>
        It's used only to connect you with programs, services, and representation.<br>
        You can update or remove any field at any time.
    </p>
</main>

<script>
document.getElementById('veteranToggle').addEventListener('change', function() {
    document.getElementById('branchGroup').style.display = this.checked ? 'block' : 'none';
});
document.getElementById('disabilityToggle').addEventListener('change', function() {
    document.getElementById('disabilityType').style.display = this.checked ? 'block' : 'none';
});

document.getElementById('benefitsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveBtn');
    const status = document.getElementById('saveStatus');
    btn.textContent = 'Saving...';
    btn.disabled = true;

    const form = this;
    const data = {};
    form.querySelectorAll('select, input[type="text"], input[type="date"], input[type="number"]').forEach(el => {
        if (el.name) data[el.name] = el.value;
    });
    form.querySelectorAll('input[type="checkbox"]').forEach(el => {
        if (el.name) data[el.name] = el.checked ? 1 : 0;
    });

    try {
        const resp = await fetch('/api/benefits-profile.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        const result = await resp.json();
        if (result.success) {
            btn.textContent = 'Saved';
            btn.classList.add('saved');
            status.textContent = result.filled + ' of ' + result.total + ' fields completed';
            document.getElementById('progressFill').style.width = Math.round((result.filled / result.total) * 100) + '%';
            document.getElementById('progressCount').textContent = result.filled + ' of ' + result.total + ' fields completed';
            document.getElementById('progressPts').textContent = '+' + (result.filled * 5) + ' of ' + (result.total * 5) + ' civic points';
            setTimeout(() => { btn.textContent = 'Save Profile'; btn.classList.remove('saved'); btn.disabled = false; }, 2000);
        } else {
            btn.textContent = 'Save Profile';
            btn.disabled = false;
            status.textContent = 'Error: ' + (result.error || 'Unknown error');
            status.style.color = '#e74c3c';
        }
    } catch(err) {
        btn.textContent = 'Save Profile';
        btn.disabled = false;
        status.textContent = 'Network error — try again';
        status.style.color = '#e74c3c';
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
