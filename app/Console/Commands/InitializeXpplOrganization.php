<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

#[Signature('organization:initialize-xppl
    {--company-name-en= : Verified English company name}
    {--company-name-lo= : Verified Lao company name}
    {--site-name-en= : Verified English site name}
    {--site-name-lo= : Verified Lao site name}')]
#[Description('Create only the XPPL company and initial XPPL site, preserving existing records')]
class InitializeXpplOrganization extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $names = [
            'company_name_en' => $this->option('company-name-en'),
            'company_name_lo' => $this->option('company-name-lo'),
            'site_name_en' => $this->option('site-name-en'),
            'site_name_lo' => $this->option('site-name-lo'),
        ];
        $validator = Validator::make($names, [
            'company_name_en' => ['required', 'string', 'max:255'],
            'company_name_lo' => ['required', 'string', 'max:255'],
            'site_name_en' => ['required', 'string', 'max:255'],
            'site_name_lo' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($names): void {
            $company = Company::query()->firstOrCreate(['code' => 'XPPL'], [
                'name_en' => $names['company_name_en'],
                'name_lo' => $names['company_name_lo'],
            ]);

            $company->sites()->firstOrCreate(['code' => 'XPPL'], [
                'name_en' => $names['site_name_en'],
                'name_lo' => $names['site_name_lo'],
            ]);
        });

        $this->info('XPPL initialization complete. Existing records were preserved; no users or departments were created.');

        return self::SUCCESS;
    }
}
