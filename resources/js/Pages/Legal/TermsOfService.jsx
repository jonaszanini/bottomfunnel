import React from 'react';
import LegalLayout from './LegalLayout';

export default function TermsOfService() {
    return (
        <LegalLayout title="Terms of Service" activeTab="terms">
            <article className="prose prose-slate dark:prose-invert max-w-none text-theme-secondary leading-relaxed">
                {/* Header */}
                <div className="border-b border-theme pb-6 mb-8">
                    <div className="inline-flex items-center gap-2 rounded-full border border-theme bg-theme-input px-3 py-1 text-xs font-medium text-theme-accent mb-4">
                        <span className="h-1.5 w-1.5 rounded-full bg-[var(--accentColor)] animate-pulse" />
                        Effective as of 21 October 2026
                    </div>
                    <h1 className="text-3xl sm:text-4xl font-extrabold text-theme-primary tracking-tight">
                        Terms of Service
                    </h1>
                    <p className="mt-2 text-sm sm:text-base text-theme-secondary">
                        Please review these terms and conditions carefully before using Bottom Funnel.
                    </p>
                </div>

                <div className="space-y-8 text-sm sm:text-base">
                    {/* 1. Terms */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                1
                            </span>
                            Terms
                        </h2>
                        <p>
                            By accessing the website at <strong className="text-theme-primary">Bottom Funnel</strong> you are agreeing to be bound by these terms of service, all applicable laws and regulations, and agree that you are responsible for compliance with any applicable local laws. If you do not agree with any of these terms, you are prohibited from using or accessing this site. The materials contained in this website are protected by applicable copyright and trademark law.
                        </p>
                    </section>

                    {/* 2. Use License */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                2
                            </span>
                            Use License
                        </h2>
                        <p>
                            Permission is granted to temporarily download one copy of the materials (information or software) on Bottom Funnel's website for personal, non-commercial transitory viewing only. This is the grant of a licence, not a transfer of title, and under this licence you may not:
                        </p>
                        <div className="rounded-xl border border-theme bg-theme-canvas/50 p-4 space-y-2">
                            <ul className="list-disc pl-5 space-y-1.5 text-sm">
                                <li>modify or copy the materials;</li>
                                <li>use the materials for any commercial purpose, or for any public display (commercial or non-commercial);</li>
                                <li>attempt to decompile or reverse engineer any software contained on Bottom Funnel website;</li>
                                <li>remove any copyright or other proprietary notations from the materials; or</li>
                                <li>transfer the materials to another person or 'mirror' the materials on any other server.</li>
                            </ul>
                        </div>
                        <p className="text-sm">
                            This licence shall automatically terminate if you violate any of these restrictions and may be terminated by Bottom Funnel at any time. Upon terminating your viewing of these materials or upon the termination of this licence, you must destroy any downloaded materials in your possession whether in electronic or printed format.
                        </p>
                    </section>

                    {/* 3. Disclaimer */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                3
                            </span>
                            Disclaimer
                        </h2>
                        <p>
                            The materials on Bottom Funnel's website are provided on an 'as is' basis. Bottom Funnel makes no warranties, expressed or implied, and hereby disclaims and negates all other warranties including, without limitation, implied warranties or conditions of merchantability, fitness for a particular purpose, or non-infringement of intellectual property or other violation of rights.
                        </p>
                        <p>
                            Further, Bottom Funnel does not warrant or make any representations concerning the accuracy, likely results, or reliability of the use of the materials on its website or otherwise relating to such materials or on any sites linked to this site.
                        </p>
                    </section>

                    {/* 4. Limitations */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                4
                            </span>
                            Limitations
                        </h2>
                        <p>
                            In no event shall Bottom Funnel or its suppliers be liable for any damages (including, without limitation, damages for loss of data or profit, or due to business interruption) arising out of the use or inability to use the materials on Bottom Funnel's website, even if Bottom Funnel or a Bottom Funnel authorised representative has been notified orally or in writing of the possibility of such damage. Because some jurisdictions do not allow limitations on implied warranties, or limitations of liability for consequential or incidental damages, these limitations may not apply to you.
                        </p>
                    </section>

                    {/* 5. Accuracy of materials */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                5
                            </span>
                            Accuracy of Materials
                        </h2>
                        <p>
                            The materials appearing on Bottom Funnel's website could include technical, typographical, or photographic errors. Bottom Funnel does not warrant that any of the materials on its website are accurate, complete or current. Bottom Funnel may make changes to the materials contained on its website at any time without notice. However Bottom Funnel does not make any commitment to update the materials.
                        </p>
                    </section>

                    {/* 6. Links */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                6
                            </span>
                            Links
                        </h2>
                        <p>
                            Bottom Funnel has not reviewed all of the sites linked to its website and is not responsible for the contents of any such linked site. The inclusion of any link does not imply endorsement by Bottom Funnel of the site. Use of any such linked website is at the user's own risk.
                        </p>
                    </section>

                    {/* 7. Modifications */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                7
                            </span>
                            Modifications
                        </h2>
                        <p>
                            Bottom Funnel may revise these terms of service for its website at any time without notice. By using this website you are agreeing to be bound by the then current version of these terms of service.
                        </p>
                    </section>

                    {/* 8. Governing Law */}
                    <section className="space-y-3">
                        <h2 className="text-lg sm:text-xl font-bold text-theme-primary flex items-center gap-2.5">
                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-[var(--accentSubtleColor)] text-xs font-bold text-[var(--accentColor)]">
                                8
                            </span>
                            Governing Law
                        </h2>
                        <p>
                            These terms and conditions are governed by and construed in accordance with the laws of Bottom Funnel and you irrevocably submit to the exclusive jurisdiction of the courts in that State or location.
                        </p>
                    </section>
                </div>
            </article>
        </LegalLayout>
    );
}
