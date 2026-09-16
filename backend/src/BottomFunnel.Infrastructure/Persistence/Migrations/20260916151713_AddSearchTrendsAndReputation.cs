using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace BottomFunnel.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class AddSearchTrendsAndReputation : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "OfferReputations",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    OfferId = table.Column<Guid>(type: "uuid", nullable: false),
                    TrustpilotRating = table.Column<decimal>(type: "numeric(3,1)", precision: 3, scale: 1, nullable: true),
                    TotalReviewsCount = table.Column<int>(type: "integer", nullable: false),
                    TrustpilotUrl = table.Column<string>(type: "text", nullable: true),
                    CalculatedRefundRisk = table.Column<int>(type: "integer", nullable: false),
                    RecurringComplaintsJson = table.Column<string>(type: "jsonb", nullable: false),
                    SentimentSummary = table.Column<string>(type: "text", nullable: true),
                    LastAuditedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    OrganizationId = table.Column<Guid>(type: "uuid", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_OfferReputations", x => x.Id);
                    table.ForeignKey(
                        name: "FK_OfferReputations_Offers_OfferId",
                        column: x => x.OfferId,
                        principalTable: "Offers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "OfferSearchTrends",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    OfferId = table.Column<Guid>(type: "uuid", nullable: false),
                    CurrentMonthlyVolume = table.Column<int>(type: "integer", nullable: false),
                    NinetyDayChangePercent = table.Column<decimal>(type: "numeric(18,4)", precision: 18, scale: 4, nullable: false),
                    Direction = table.Column<int>(type: "integer", nullable: false),
                    VolumeHistoryJson = table.Column<string>(type: "jsonb", nullable: false),
                    LastRefreshedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    OrganizationId = table.Column<Guid>(type: "uuid", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_OfferSearchTrends", x => x.Id);
                    table.ForeignKey(
                        name: "FK_OfferSearchTrends_Offers_OfferId",
                        column: x => x.OfferId,
                        principalTable: "Offers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateIndex(
                name: "IX_OfferReputations_OfferId",
                table: "OfferReputations",
                column: "OfferId",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_OfferReputations_OrganizationId_OfferId",
                table: "OfferReputations",
                columns: new[] { "OrganizationId", "OfferId" },
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_OfferSearchTrends_OfferId",
                table: "OfferSearchTrends",
                column: "OfferId",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_OfferSearchTrends_OrganizationId_OfferId",
                table: "OfferSearchTrends",
                columns: new[] { "OrganizationId", "OfferId" },
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "OfferReputations");

            migrationBuilder.DropTable(
                name: "OfferSearchTrends");
        }
    }
}
