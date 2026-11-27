@extends('layouts.app', ['title' => __('github-docs.title').' | Auto QA'])

@section('content')
    <div class="docs-page">
        <header class="docs-header">
            <div class="docs-header-inner">
                <a class="brand" href="{{ route(auth()->check() ? 'dashboard' : 'login') }}">Auto QA</a>
                <a class="button button-secondary" href="{{ route(auth()->check() ? 'dashboard' : 'login') }}">
                    {{ auth()->check() ? __('github-docs.workspace') : __('auth-ui.signin') }}
                </a>
            </div>
        </header>

        <main id="main" class="docs-main" tabindex="-1">
            <div class="docs-intro">
                <h1>{{ __('github-docs.title') }}</h1>
                <p>{{ __('github-docs.introduction') }}</p>
            </div>

            <div class="docs-layout">
                <nav class="docs-sidebar" aria-labelledby="docs-navigation-title">
                    <h2 id="docs-navigation-title">{{ __('github-docs.on_this_page') }}</h2>
                    <ul>
                        <li><a href="#install">{{ __('github-docs.install_heading') }}</a></li>
                        <li><a href="#approve">{{ __('github-docs.approval_heading') }}</a></li>
                        <li><a href="#connect">{{ __('github-docs.connect_heading') }}</a></li>
                        <li><a href="#permissions">{{ __('github-docs.permissions_heading') }}</a></li>
                        <li><a href="#troubleshooting">{{ __('github-docs.troubleshooting_heading') }}</a></li>
                    </ul>
                </nav>

                <article class="docs-content" aria-label="{{ __('github-docs.nav_label') }}">
                    <section class="docs-section" id="install" aria-labelledby="docs-install-title">
                        <h2 id="docs-install-title">{{ __('github-docs.install_heading') }}</h2>
                        <ol class="docs-steps">
                            <li>
                                <strong>{{ __('github-docs.install_settings_label') }}</strong>
                                <p>{{ __('github-docs.install_settings_text') }}</p>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.install_account_label') }}</strong>
                                <p>{{ __('github-docs.install_account_text') }}</p>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.install_repositories_label') }}</strong>
                                <p>{{ __('github-docs.install_repositories_text') }}</p>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.install_review_label') }}</strong>
                                <p>{{ __('github-docs.install_review_text') }}</p>
                            </li>
                        </ol>

                        <div class="docs-figure-grid">
                            <figure class="docs-figure">
                                <a href="{{ asset('images/docs/github-app/01-autoqa-github-settings.jpg') }}">
                                    <img src="{{ asset('images/docs/github-app/01-autoqa-github-settings.jpg') }}" alt="{{ __('github-docs.settings_alt') }}" width="454" height="276" loading="lazy" decoding="async">
                                </a>
                                <figcaption>{{ __('github-docs.settings_caption') }}</figcaption>
                            </figure>
                            <figure class="docs-figure">
                                <a href="{{ asset('images/docs/github-app/02-github-account-chooser.jpg') }}">
                                    <img src="{{ asset('images/docs/github-app/02-github-account-chooser.jpg') }}" alt="{{ __('github-docs.account_alt') }}" width="516" height="143" loading="lazy" decoding="async">
                                </a>
                                <figcaption>{{ __('github-docs.account_caption') }}</figcaption>
                            </figure>
                        </div>

                        @if ($installationUrl)
                            <div class="docs-actions">
                                <a class="button button-primary" href="{{ $installationUrl }}">{{ __('ui.install_on_github') }}</a>
                            </div>
                        @endif
                    </section>

                    <section class="docs-section" id="approve" aria-labelledby="docs-approval-title">
                        <h2 id="docs-approval-title">{{ __('github-docs.approval_heading') }}</h2>
                        <p>{{ __('github-docs.approval_text') }}</p>
                        @auth
                            <div class="docs-actions">
                                <a class="button button-secondary" href="{{ route('dashboard', ['panel' => 'settings']) }}#github-connection">{{ __('dashboard-panels.github.account_settings') }}</a>
                            </div>
                        @endauth
                        <p class="docs-callout">{{ __('github-docs.manual_approval') }}</p>
                        <ul class="docs-approval-details">
                            <li>{{ __('github-docs.approval_account') }}</li>
                            <li>{{ __('github-docs.approval_repository') }} <code>owner/repository</code></li>
                        </ul>
                    </section>

                    <section class="docs-section" id="connect" aria-labelledby="docs-connect-title">
                        <h2 id="docs-connect-title">{{ __('github-docs.connect_heading') }}</h2>
                        <ol class="docs-steps">
                            <li>
                                <strong>{{ __('github-docs.connect_copy_label') }}</strong>
                                <p>{{ __('github-docs.connect_copy_text') }} <code>https://github.com/owner/repository</code></p>
                                <figure class="docs-figure">
                                    <a href="{{ asset('images/docs/github-app/03-github-repository-url.jpg') }}">
                                        <img src="{{ asset('images/docs/github-app/03-github-repository-url.jpg') }}" alt="{{ __('github-docs.repository_alt') }}" width="416" height="296" loading="lazy" decoding="async">
                                    </a>
                                    <figcaption>{{ __('github-docs.repository_caption') }}</figcaption>
                                </figure>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.connect_save_label') }}</strong>
                                <p>{{ __('github-docs.connect_save_text') }}</p>
                                <figure class="docs-figure">
                                    <a href="{{ asset('images/docs/github-app/04-autoqa-new-project.jpg') }}">
                                        <img src="{{ asset('images/docs/github-app/04-autoqa-new-project.jpg') }}" alt="{{ __('github-docs.project_alt') }}" width="1062" height="728" loading="lazy" decoding="async">
                                    </a>
                                    <figcaption>{{ __('github-docs.project_caption') }}</figcaption>
                                </figure>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.connect_check_label') }}</strong>
                                <p>{{ __('github-docs.connect_check_text') }}</p>
                            </li>
                        </ol>
                        <p class="docs-callout">{{ __('github-docs.report_only') }}</p>
                    </section>

                    <section class="docs-section" id="permissions" aria-labelledby="docs-permissions-title">
                        <h2 id="docs-permissions-title">{{ __('github-docs.permissions_heading') }}</h2>
                        <p>{{ __('github-docs.permissions_intro') }}</p>
                        <ol class="docs-steps">
                            <li>
                                <strong>{{ __('github-docs.permissions_open_label') }}</strong>
                                <p>{{ __('github-docs.permissions_open_text') }}</p>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.permissions_set_label') }}</strong>
                                <p>{{ __('github-docs.permissions_set_text') }}</p>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.permissions_accept_label') }}</strong>
                                <p>{{ __('github-docs.permissions_accept_text') }}</p>
                            </li>
                            <li>
                                <strong>{{ __('github-docs.permissions_verify_label') }}</strong>
                                <p>{{ __('github-docs.permissions_verify_text') }}</p>
                            </li>
                        </ol>
                        <p class="docs-callout">{{ __('github-docs.permissions_review_identity') }}</p>
                        <p>{{ __('github-docs.permissions_check_details') }}</p>
                        <h3 class="docs-reference">{{ __('github-docs.oauth_heading') }}</h3>
                        <p>{{ __('github-docs.oauth_callback') }} <code>{{ rtrim((string) config('app.url'), '/').route('settings.github.callback', absolute: false) }}</code></p>
                        <p>{{ __('github-docs.oauth_manual_connect') }}</p>
                        <p>{{ __('github-docs.oauth_credentials') }} <code>GITHUB_APP_CLIENT_ID</code> {{ __('github-docs.oauth_and') }} <code>GITHUB_APP_CLIENT_SECRET</code>.</p>
                        <p>{{ __('github-docs.oauth_deploy') }}</p>
                        <p class="docs-reference">
                            <a href="https://docs.github.com/en/apps/maintaining-github-apps/modifying-a-github-app-registration#changing-the-permissions-of-a-github-app">{{ __('github-docs.permissions_reference') }}</a>
                        </p>
                        <p class="docs-reference">
                            <a href="https://docs.github.com/en/apps/creating-github-apps/registering-a-github-app/about-the-user-authorization-callback-url">{{ __('github-docs.oauth_reference') }}</a>
                        </p>
                    </section>

                    <section class="docs-section" id="troubleshooting" aria-labelledby="docs-troubleshooting-title">
                        <h2 id="docs-troubleshooting-title">{{ __('github-docs.troubleshooting_heading') }}</h2>
                        <dl class="docs-checks">
                            <div>
                                <dt>{{ __('github-docs.missing_install_title') }}</dt>
                                <dd>{{ __('github-docs.missing_install_text') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('github-docs.access_denied_title') }}</dt>
                                <dd>{{ __('github-docs.access_denied_text') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('github-docs.read_only_title') }}</dt>
                                <dd>{{ __('github-docs.read_only_text') }}</dd>
                            </div>
                        </dl>
                        <p class="docs-reference">
                            <a href="https://docs.github.com/en/apps/using-github-apps/installing-a-github-app-from-a-third-party">{{ __('github-docs.github_reference') }}</a>
                        </p>
                    </section>
                </article>
            </div>
        </main>
    </div>
@endsection
