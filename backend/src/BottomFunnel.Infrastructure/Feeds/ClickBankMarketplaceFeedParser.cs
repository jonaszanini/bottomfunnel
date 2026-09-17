using System.Globalization;
using System.Xml;

namespace BottomFunnel.Infrastructure.Feeds;

public sealed record ClickBankFeedSite(
    string ExternalId,
    string Title,
    string Description,
    decimal Gravity,
    string? Category,
    string? AffiliatePageUrl);

public static class ClickBankMarketplaceFeedParser
{
    public static IEnumerable<ClickBankFeedSite> ReadSites(Stream xml)
    {
        using var reader = XmlReader.Create(xml, new XmlReaderSettings
        {
            DtdProcessing = DtdProcessing.Ignore,
            XmlResolver = null,
            IgnoreComments = true,
            IgnoreProcessingInstructions = true,
            IgnoreWhitespace = true,
            CloseInput = false
        });

        while (reader.Read())
        {
            if (reader.NodeType == XmlNodeType.Element && Is(reader, "Category") && !reader.IsEmptyElement)
            {
                using var subtree = reader.ReadSubtree();
                foreach (var site in ReadCategory(subtree, []))
                    yield return site;
            }
        }
    }

    private static IEnumerable<ClickBankFeedSite> ReadCategory(XmlReader reader, IReadOnlyList<string> parents)
    {
        reader.Read();
        var names = parents.ToList();
        var capturedName = false;

        while (reader.Read())
        {
            if (reader.NodeType == XmlNodeType.Element && Is(reader, "Name") && !capturedName)
            {
                var name = reader.ReadElementContentAsString().Trim();
                capturedName = true;
                if (name.Length > 0)
                    names.Add(name);
                if (reader.NodeType == XmlNodeType.Element)
                {
                    foreach (var site in ReadChild(reader, names))
                        yield return site;
                }
                continue;
            }

            if (reader.NodeType == XmlNodeType.Element)
            {
                foreach (var site in ReadChild(reader, names))
                    yield return site;
            }
        }
    }

    private static IEnumerable<ClickBankFeedSite> ReadChild(XmlReader reader, IReadOnlyList<string> names)
    {
        if (Is(reader, "Site") && !reader.IsEmptyElement)
        {
            using var siteReader = reader.ReadSubtree();
            var site = ReadSite(siteReader, CategoryPath(names));
            if (site is not null)
                yield return site;
            yield break;
        }

        if (Is(reader, "Category") && !reader.IsEmptyElement)
        {
            using var nested = reader.ReadSubtree();
            foreach (var site in ReadCategory(nested, names))
                yield return site;
        }
    }

    private static string? CategoryPath(IReadOnlyList<string> names) =>
        names.Count == 0 ? null : string.Join(" / ", names);

    private static ClickBankFeedSite? ReadSite(XmlReader reader, string? category)
    {
        reader.Read();
        string? id = null;
        string? name = null;
        string? title = null;
        var description = "";
        decimal gravity = 0;
        string? url = null;

        var moved = reader.Read();
        while (moved)
        {
            if (reader.NodeType != XmlNodeType.Element)
            {
                moved = reader.Read();
                continue;
            }

            var local = reader.LocalName;
            var text = reader.ReadElementContentAsString().Trim();
            switch (local)
            {
                case "Id":
                    id = text;
                    break;
                case "Name":
                    name = text;
                    break;
                case "Title":
                    title = text;
                    break;
                case "Description":
                    description = text;
                    break;
                case "Gravity":
                    decimal.TryParse(text, NumberStyles.Number, CultureInfo.InvariantCulture, out gravity);
                    break;
                case "AffiliateLaunchPage":
                case "AffiliateUrl":
                case "Url":
                    if (!string.IsNullOrWhiteSpace(text) && url is null)
                        url = text;
                    break;
            }
        }

        var externalId = FirstNonEmpty(id, name);
        if (string.IsNullOrWhiteSpace(externalId))
            return null;
        var siteTitle = FirstNonEmpty(title, name, externalId) ?? externalId;
        return new ClickBankFeedSite(externalId.ToLowerInvariant(), siteTitle, description, gravity, category, url);
    }

    private static bool Is(XmlReader reader, string localName) =>
        reader.LocalName.Equals(localName, StringComparison.OrdinalIgnoreCase);

    private static string? FirstNonEmpty(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }
        return null;
    }
}
