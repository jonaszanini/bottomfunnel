using BottomFunnel.Infrastructure.Currency;
using FluentAssertions;

namespace BottomFunnel.UnitTests;

public class BankOfCanadaCurrencyServiceTests
{
    [Fact]
    public void Parses_latest_fxusdcad_observation()
    {
        const string json = """
        {
          "observations": [
            { "d": "2026-09-15", "FXUSDCAD": { "v": "1.3917" } }
          ]
        }
        """;
        BankOfCanadaCurrencyService.ParseLatest(json, "FXUSDCAD").Should().Be(1.3917m);
    }
}
