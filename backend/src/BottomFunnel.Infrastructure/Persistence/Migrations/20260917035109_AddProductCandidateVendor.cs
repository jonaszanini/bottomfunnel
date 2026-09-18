using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace BottomFunnel.Infrastructure.Persistence.Migrations
{
    /// <inheritdoc />
    public partial class AddProductCandidateVendor : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "Vendor",
                table: "ProductCandidates",
                type: "character varying(200)",
                maxLength: 200,
                nullable: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "Vendor",
                table: "ProductCandidates");
        }
    }
}
