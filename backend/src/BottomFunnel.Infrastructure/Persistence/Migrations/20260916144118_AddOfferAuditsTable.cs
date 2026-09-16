using System;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace BottomFunnel.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class AddOfferAuditsTable : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "OfferAudits",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    OfferId = table.Column<Guid>(type: "uuid", nullable: false),
                    RiskScore = table.Column<int>(type: "integer", nullable: false),
                    OverallRisk = table.Column<string>(type: "character varying(32)", maxLength: 32, nullable: false),
                    AllowsBrandBidding = table.Column<bool>(type: "boolean", nullable: false),
                    ResultJson = table.Column<string>(type: "jsonb", nullable: false),
                    CreatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    UpdatedAt = table.Column<DateTimeOffset>(type: "timestamp with time zone", nullable: false),
                    OrganizationId = table.Column<Guid>(type: "uuid", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_OfferAudits", x => x.Id);
                    table.ForeignKey(
                        name: "FK_OfferAudits_Offers_OfferId",
                        column: x => x.OfferId,
                        principalTable: "Offers",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateIndex(
                name: "IX_OfferAudits_OfferId",
                table: "OfferAudits",
                column: "OfferId");

            migrationBuilder.CreateIndex(
                name: "IX_OfferAudits_OrganizationId_OfferId_CreatedAt",
                table: "OfferAudits",
                columns: new[] { "OrganizationId", "OfferId", "CreatedAt" });
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "OfferAudits");
        }
    }
}
