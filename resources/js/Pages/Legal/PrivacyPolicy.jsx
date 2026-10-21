import React from 'react';
import LegalLayout from './LegalLayout';

export default function PrivacyPolicy() {
    return (
        <LegalLayout title="Privacy Policy" activeTab="privacy">
            <article className="prose prose-slate dark:prose-invert max-w-none text-theme-secondary leading-relaxed">
                {/* Header */}
                <div className="border-b border-theme pb-6 mb-8">
                    <div className="inline-flex items-center gap-2 rounded-full border border-theme bg-theme-input px-3 py-1 text-xs font-medium text-theme-accent mb-4">
                        <span className="h-1.5 w-1.5 rounded-full bg-[var(--accentColor)] animate-pulse" />
                        Effective as of 21 October 2026 20:58
                    </div>
                    <h1 className="text-3xl sm:text-4xl font-extrabold text-theme-primary tracking-tight">
                        Privacy Policy
                    </h1>
                    <p className="mt-2 text-sm sm:text-base text-theme-secondary">
                        How Bottom Funnel collects, protects, and handles your personal information across our website and services.
                    </p>
                </div>

                {/* General Policy */}
                <section className="space-y-4 mb-10 text-sm sm:text-base">
                    <p>
                        Your privacy is important to us. It is Bottom Funnel's policy to respect your privacy regarding any information we may collect from you across our website, <strong className="text-theme-primary">Bottom Funnel</strong>, and other sites we own and operate.
                    </p>
                    <p>
                        We only ask for personal information when we truly need it to provide a service to you. We collect it by fair and lawful means, with your knowledge and consent. We also let you know why we’re collecting it and how it will be used.
                    </p>
                    <p>
                        We only retain collected information for as long as necessary to provide you with your requested service. What data we store, we’ll protect within commercially acceptable means to prevent loss and theft, as well as unauthorised access, disclosure, copying, use or modification.
                    </p>
                    <p>
                        We don’t share any personally identifying information publicly or with third-parties, except when required to by law.
                    </p>
                    <p>
                        Our website may link to external sites that are not operated by us. Please be aware that we have no control over the content and practices of these sites, and cannot accept responsibility or liability for their respective privacy policies.
                    </p>
                    <p>
                        You are free to refuse our request for your personal information, with the understanding that we may be unable to provide you with some of your desired services.
                    </p>
                    <p>
                        Your continued use of our website will be regarded as acceptance of our practices around privacy and personal information. If you have any questions about how we handle user data and personal information, feel free to contact us.
                    </p>
                </section>

                {/* Cookie Policy Header Card */}
                <div className="rounded-xl border border-theme bg-theme-canvas/60 p-6 mb-8 transition-colors">
                    <h2 className="text-xl sm:text-2xl font-bold text-theme-primary tracking-tight mb-2">
                        Cookie Policy for Bottom Funnel
                    </h2>
                    <p className="text-sm text-theme-secondary">
                        This is the Cookie Policy for Bottom Funnel, accessible from URL{' '}
                        <a
                            href="https://bottomfunnel.app/"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-theme-accent hover:underline"
                        >
                            https://bottomfunnel.app/
                        </a>.
                    </p>
                </div>

                {/* What Are Cookies */}
                <section className="space-y-3 mb-8 text-sm sm:text-base">
                    <h3 className="text-lg sm:text-xl font-bold text-theme-primary">
                        What Are Cookies
                    </h3>
                    <p>
                        As is common practice with almost all professional websites this site uses cookies, which are tiny files that are downloaded to your computer, to improve your experience. This page describes what information they gather, how we use it and why we sometimes need to store these cookies. We will also share how you can prevent these cookies from being stored however this may downgrade or break certain elements of the sites functionality.
                    </p>
                </section>

                {/* How We Use Cookies */}
                <section className="space-y-3 mb-8 text-sm sm:text-base">
                    <h3 className="text-lg sm:text-xl font-bold text-theme-primary">
                        How We Use Cookies
                    </h3>
                    <p>
                        We use cookies for a variety of reasons detailed below. Unfortunately in most cases there are no industry standard options for disabling cookies without completely disabling the functionality and features they add to this site. It is recommended that you leave on all cookies if you are not sure whether you need them or not in case they are used to provide a service that you use.
                    </p>
                </section>

                {/* Disabling Cookies */}
                <section className="space-y-3 mb-8 text-sm sm:text-base">
                    <h3 className="text-lg sm:text-xl font-bold text-theme-primary">
                        Disabling Cookies
                    </h3>
                    <p>
                        You can prevent the setting of cookies by adjusting the settings on your browser (see your browser Help for how to do this). Be aware that disabling cookies will affect the functionality of this and many other websites that you visit. Disabling cookies will usually result in also disabling certain functionality and features of this site. Therefore it is recommended that you do not disable cookies.
                    </p>
                </section>

                {/* The Cookies We Set */}
                <section className="space-y-4 mb-8 text-sm sm:text-base">
                    <h3 className="text-lg sm:text-xl font-bold text-theme-primary">
                        The Cookies We Set
                    </h3>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-theme bg-theme-input/40 p-4">
                            <h4 className="font-semibold text-theme-primary text-sm mb-1">
                                Account &amp; Authentication Cookies
                            </h4>
                            <p className="text-xs sm:text-sm text-theme-secondary">
                                If you create an account with us, we will use cookies for session management, login security, and general administration. These cookies ensure that you remain authenticated during your session.
                            </p>
                        </div>
                        <div className="rounded-lg border border-theme bg-theme-input/40 p-4">
                            <h4 className="font-semibold text-theme-primary text-sm mb-1">
                                Site Preferences &amp; Theme Cookies
                            </h4>
                            <p className="text-xs sm:text-sm text-theme-secondary">
                                To provide an optimal user experience, we store local preferences such as light/dark mode selection and workspace filters so they are recalled when you visit pages.
                            </p>
                        </div>
                    </div>
                </section>

                {/* Third Party Cookies */}
                <section className="space-y-3 mb-8 text-sm sm:text-base">
                    <h3 className="text-lg sm:text-xl font-bold text-theme-primary">
                        Third Party Cookies
                    </h3>
                    <p>
                        In some special cases we also use cookies provided by trusted third parties. The following section details which third party cookies you might encounter through this site.
                    </p>
                    <ul className="list-disc pl-5 space-y-2 text-sm">
                        <li>
                            Third-party analytics and tracking providers to measure traffic performance, conversion paths, and site reliability.
                        </li>
                        <li>
                            Platform API integrations (such as Google Ads API, tracking webhooks, and FX exchange rate data) to facilitate secure token management and real-time synchronization.
                        </li>
                    </ul>
                </section>

                {/* User's responsibilities */}
                <section className="space-y-4 mb-8 text-sm sm:text-base">
                    <h3 className="text-lg sm:text-xl font-bold text-theme-primary">
                        User's Responsibilities
                    </h3>
                    <p>
                        The user undertakes the responsibility to make appropriate use of the contents and information offered on the site with enunciative, but not imitative, behaviour:
                    </p>
                    <div className="space-y-3">
                        <div className="flex items-start gap-3 rounded-lg border border-theme bg-theme-canvas/40 p-3.5">
                            <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                A
                            </span>
                            <p className="text-sm">
                                Not to engage in activities that are illegal or contrary to good faith and public order;
                            </p>
                        </div>
                        <div className="flex items-start gap-3 rounded-lg border border-theme bg-theme-canvas/40 p-3.5">
                            <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                B
                            </span>
                            <p className="text-sm">
                                Not to spread propaganda or content of a racist, xenophobic or gambling nature, any type of illegal pornography, terrorist claims or against human rights;
                            </p>
                        </div>
                        <div className="flex items-start gap-3 rounded-lg border border-theme bg-theme-canvas/40 p-3.5">
                            <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                C
                            </span>
                            <p className="text-sm">
                                Do not cause damage to physical systems (hardware) and unattainable (software) of Bottom Funnel, its suppliers or third parties, to introduce or disseminate computer viruses or any other hardware or software systems that are capable of causing damage previously mentioned.
                            </p>
                        </div>
                    </div>
                </section>

                {/* More information */}
                <section className="rounded-xl border border-theme bg-theme-input/30 p-6 text-sm sm:text-base">
                    <h3 className="text-lg font-bold text-theme-primary mb-2">
                        More Information
                    </h3>
                    <p className="text-theme-secondary mb-3">
                        Hopefully that has clarified things for you and as was previously mentioned if there is something that you aren't sure whether you need or not it's usually safer to leave cookies enabled in case it does interact with one of the features you use on our site.
                    </p>
                    <p className="text-xs font-mono text-theme-muted">
                        This policy is effective as of 21 October 2026 20:58.
                    </p>
                </section>
            </article>
        </LegalLayout>
    );
}
