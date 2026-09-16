using System.Net;
using System.Text.RegularExpressions;

namespace BottomFunnel.Application.Offers;

public static class HtmlContentSanitizer
{
    private static readonly Regex Script = new(@"<script[\s\S]*?</script>", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex Style = new(@"<style[\s\S]*?</style>", RegexOptions.IgnoreCase | RegexOptions.Compiled);
    private static readonly Regex Tags = new(@"<[^>]+>", RegexOptions.Compiled);
    private static readonly Regex Whitespace = new(@"\s+", RegexOptions.Compiled);

    public static string ToPlainText(string htmlOrText, int maxChars = 20_000)
    {
        if (string.IsNullOrWhiteSpace(htmlOrText))
            return "";
        var text = Script.Replace(htmlOrText, " ");
        text = Style.Replace(text, " ");
        text = Tags.Replace(text, " ");
        text = WebUtility.HtmlDecode(text);
        text = Whitespace.Replace(text, " ").Trim();
        if (text.Length <= maxChars)
            return text;
        return text[..maxChars];
    }
}
