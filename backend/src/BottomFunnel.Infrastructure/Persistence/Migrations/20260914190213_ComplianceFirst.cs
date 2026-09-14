using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace BottomFunnel.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class ComplianceFirst : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "AdRestrictions",
                table: "Offers",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "BrandBiddingAllowed",
                table: "Offers",
                type: "boolean",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "BrandPlusModifierAllowed",
                table: "Offers",
                type: "boolean",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "CouponBiddingAllowed",
                table: "Offers",
                type: "boolean",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "DirectLinkingAllowed",
                table: "Offers",
                type: "boolean",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "LandingPageRestrictions",
                table: "Offers",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "PolicyNotes",
                table: "Offers",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "PpcAllowed",
                table: "Offers",
                type: "boolean",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "TrademarkNotes",
                table: "Offers",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "AdRestrictions",
                table: "Merchants",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "BrandBiddingAllowed",
                table: "Merchants",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<bool>(
                name: "BrandPlusModifierAllowed",
                table: "Merchants",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<bool>(
                name: "CouponBiddingAllowed",
                table: "Merchants",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<bool>(
                name: "DirectLinkingAllowed",
                table: "Merchants",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<string>(
                name: "LandingPageRestrictions",
                table: "Merchants",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "PolicyNotes",
                table: "Merchants",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<bool>(
                name: "PpcAllowed",
                table: "Merchants",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<string>(
                name: "TrademarkNotes",
                table: "Merchants",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "Alternatives",
                table: "LandingPages",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ConsJson",
                table: "LandingPages",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "Disclosure",
                table: "LandingPages",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "ProsJson",
                table: "LandingPages",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "WhoItsFor",
                table: "LandingPages",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "WhoItsNotFor",
                table: "LandingPages",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "BlockedReason",
                table: "Keywords",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<int>(
                name: "BrandStatus",
                table: "Keywords",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<int>(
                name: "ComplianceStatus",
                table: "Keywords",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<int>(
                name: "CommercialScore",
                table: "KeywordOpportunities",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<int>(
                name: "ComplianceScore",
                table: "KeywordOpportunities",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<int>(
                name: "Confidence",
                table: "KeywordOpportunities",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<decimal>(
                name: "ExpectedProfitBase",
                table: "KeywordOpportunities",
                type: "numeric(18,4)",
                precision: 18,
                scale: 4,
                nullable: false,
                defaultValue: 0m);

            migrationBuilder.AddColumn<decimal>(
                name: "ExpectedProfitConservative",
                table: "KeywordOpportunities",
                type: "numeric(18,4)",
                precision: 18,
                scale: 4,
                nullable: false,
                defaultValue: 0m);

            migrationBuilder.AddColumn<decimal>(
                name: "ExpectedProfitOptimistic",
                table: "KeywordOpportunities",
                type: "numeric(18,4)",
                precision: 18,
                scale: 4,
                nullable: false,
                defaultValue: 0m);

            migrationBuilder.AddColumn<int>(
                name: "ViabilityScore",
                table: "KeywordOpportunities",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<string>(
                name: "Why",
                table: "KeywordOpportunities",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<int>(
                name: "LowSearchVolumeRisk",
                table: "KeywordMetrics",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<int>(
                name: "SearchVolumeConfidence",
                table: "KeywordMetrics",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<string>(
                name: "SearchVolumePeriod",
                table: "KeywordMetrics",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "SearchVolumeSource",
                table: "KeywordMetrics",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<int>(
                name: "AttributionConfidence",
                table: "Conversions",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<string>(
                name: "Gclid",
                table: "Conversions",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<Guid>(
                name: "LandingPageId",
                table: "Conversions",
                type: "uuid",
                nullable: true);

            migrationBuilder.AddColumn<int>(
                name: "Provenance",
                table: "Conversions",
                type: "integer",
                nullable: false,
                defaultValue: 0);

            migrationBuilder.AddColumn<Guid>(
                name: "SessionId",
                table: "Conversions",
                type: "uuid",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "SubId",
                table: "Conversions",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "Gclid",
                table: "Clicks",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<Guid>(
                name: "SessionId",
                table: "Clicks",
                type: "uuid",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "SubId",
                table: "Clicks",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<Guid>(
                name: "VisitId",
                table: "Clicks",
                type: "uuid",
                nullable: true);

            migrationBuilder.CreateTable(
                name: "ComplianceRestrictions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    MerchantId = table.Column<Guid>(type: "uuid", nullable: true),
                    OfferId = table.Column<Guid>(type: "uuid", nullable: true),
                    Kind = table.Column<int>(type: "integer", nullable: false),
                    State = table.Column<int>(type: "integer", nullable: false),
                    Source = table.Column<string>(type: "text", nullable: true),
                    Evidence = table.Column<string>(type: "text", nullable: true),
                    CheckedAtUtc = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    NextReviewAtUtc = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: true),
                    Confidence = table.Column<int>(type: "integer", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    OrganizationId = table.Column<Guid>(type: "uuid", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_ComplianceRestrictions", x => x.Id);
                    table.ForeignKey(
                        name: "FK_ComplianceRestrictions_Merchants_MerchantId",
                        column: x => x.MerchantId,
                        principalTable: "Merchants",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.SetNull);
                    table.ForeignKey(
                        name: "FK_ComplianceRestrictions_Offers_OfferId",
                        column: x => x.OfferId,
                        principalTable: "Offers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "VisitorSessions",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    SessionKey = table.Column<string>(type: "text", nullable: false),
                    LastSeenAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    OrganizationId = table.Column<Guid>(type: "uuid", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_VisitorSessions", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "Visits",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    SessionId = table.Column<Guid>(type: "uuid", nullable: false),
                    LandingPageId = table.Column<Guid>(type: "uuid", nullable: true),
                    CampaignId = table.Column<Guid>(type: "uuid", nullable: true),
                    KeywordId = table.Column<Guid>(type: "uuid", nullable: true),
                    Gclid = table.Column<string>(type: "text", nullable: true),
                    UserAgent = table.Column<string>(type: "text", nullable: true),
                    Device = table.Column<string>(type: "text", nullable: true),
                    Referrer = table.Column<string>(type: "text", nullable: true),
                    OccurredAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    OrganizationId = table.Column<Guid>(type: "uuid", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_Visits", x => x.Id);
                    table.ForeignKey(
                        name: "FK_Visits_VisitorSessions_SessionId",
                        column: x => x.SessionId,
                        principalTable: "VisitorSessions",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateIndex(
                name: "IX_Conversions_ExternalOrderId",
                table: "Conversions",
                column: "ExternalOrderId");

            migrationBuilder.CreateIndex(
                name: "IX_Conversions_Gclid",
                table: "Conversions",
                column: "Gclid");

            migrationBuilder.CreateIndex(
                name: "IX_Clicks_Gclid",
                table: "Clicks",
                column: "Gclid");

            migrationBuilder.CreateIndex(
                name: "IX_Clicks_SessionId",
                table: "Clicks",
                column: "SessionId");

            migrationBuilder.CreateIndex(
                name: "IX_ComplianceRestrictions_MerchantId",
                table: "ComplianceRestrictions",
                column: "MerchantId");

            migrationBuilder.CreateIndex(
                name: "IX_ComplianceRestrictions_OfferId",
                table: "ComplianceRestrictions",
                column: "OfferId");

            migrationBuilder.CreateIndex(
                name: "IX_ComplianceRestrictions_OrganizationId_MerchantId_Kind",
                table: "ComplianceRestrictions",
                columns: new[] { "OrganizationId", "MerchantId", "Kind" });

            migrationBuilder.CreateIndex(
                name: "IX_ComplianceRestrictions_OrganizationId_OfferId_Kind",
                table: "ComplianceRestrictions",
                columns: new[] { "OrganizationId", "OfferId", "Kind" });

            migrationBuilder.CreateIndex(
                name: "IX_VisitorSessions_OrganizationId_SessionKey",
                table: "VisitorSessions",
                columns: new[] { "OrganizationId", "SessionKey" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_Visits_Gclid",
                table: "Visits",
                column: "Gclid");

            migrationBuilder.CreateIndex(
                name: "IX_Visits_OrganizationId_LandingPageId_OccurredAt",
                table: "Visits",
                columns: new[] { "OrganizationId", "LandingPageId", "OccurredAt" });

            migrationBuilder.CreateIndex(
                name: "IX_Visits_SessionId",
                table: "Visits",
                column: "SessionId");
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "ComplianceRestrictions");

            migrationBuilder.DropTable(
                name: "Visits");

            migrationBuilder.DropTable(
                name: "VisitorSessions");

            migrationBuilder.DropIndex(
                name: "IX_Conversions_ExternalOrderId",
                table: "Conversions");

            migrationBuilder.DropIndex(
                name: "IX_Conversions_Gclid",
                table: "Conversions");

            migrationBuilder.DropIndex(
                name: "IX_Clicks_Gclid",
                table: "Clicks");

            migrationBuilder.DropIndex(
                name: "IX_Clicks_SessionId",
                table: "Clicks");

            migrationBuilder.DropColumn(
                name: "AdRestrictions",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "BrandBiddingAllowed",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "BrandPlusModifierAllowed",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "CouponBiddingAllowed",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "DirectLinkingAllowed",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "LandingPageRestrictions",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "PolicyNotes",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "PpcAllowed",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "TrademarkNotes",
                table: "Offers");

            migrationBuilder.DropColumn(
                name: "AdRestrictions",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "BrandBiddingAllowed",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "BrandPlusModifierAllowed",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "CouponBiddingAllowed",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "DirectLinkingAllowed",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "LandingPageRestrictions",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "PolicyNotes",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "PpcAllowed",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "TrademarkNotes",
                table: "Merchants");

            migrationBuilder.DropColumn(
                name: "Alternatives",
                table: "LandingPages");

            migrationBuilder.DropColumn(
                name: "ConsJson",
                table: "LandingPages");

            migrationBuilder.DropColumn(
                name: "Disclosure",
                table: "LandingPages");

            migrationBuilder.DropColumn(
                name: "ProsJson",
                table: "LandingPages");

            migrationBuilder.DropColumn(
                name: "WhoItsFor",
                table: "LandingPages");

            migrationBuilder.DropColumn(
                name: "WhoItsNotFor",
                table: "LandingPages");

            migrationBuilder.DropColumn(
                name: "BlockedReason",
                table: "Keywords");

            migrationBuilder.DropColumn(
                name: "BrandStatus",
                table: "Keywords");

            migrationBuilder.DropColumn(
                name: "ComplianceStatus",
                table: "Keywords");

            migrationBuilder.DropColumn(
                name: "CommercialScore",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "ComplianceScore",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "Confidence",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "ExpectedProfitBase",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "ExpectedProfitConservative",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "ExpectedProfitOptimistic",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "ViabilityScore",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "Why",
                table: "KeywordOpportunities");

            migrationBuilder.DropColumn(
                name: "LowSearchVolumeRisk",
                table: "KeywordMetrics");

            migrationBuilder.DropColumn(
                name: "SearchVolumeConfidence",
                table: "KeywordMetrics");

            migrationBuilder.DropColumn(
                name: "SearchVolumePeriod",
                table: "KeywordMetrics");

            migrationBuilder.DropColumn(
                name: "SearchVolumeSource",
                table: "KeywordMetrics");

            migrationBuilder.DropColumn(
                name: "AttributionConfidence",
                table: "Conversions");

            migrationBuilder.DropColumn(
                name: "Gclid",
                table: "Conversions");

            migrationBuilder.DropColumn(
                name: "LandingPageId",
                table: "Conversions");

            migrationBuilder.DropColumn(
                name: "Provenance",
                table: "Conversions");

            migrationBuilder.DropColumn(
                name: "SessionId",
                table: "Conversions");

            migrationBuilder.DropColumn(
                name: "SubId",
                table: "Conversions");

            migrationBuilder.DropColumn(
                name: "Gclid",
                table: "Clicks");

            migrationBuilder.DropColumn(
                name: "SessionId",
                table: "Clicks");

            migrationBuilder.DropColumn(
                name: "SubId",
                table: "Clicks");

            migrationBuilder.DropColumn(
                name: "VisitId",
                table: "Clicks");
        }
    }
}
